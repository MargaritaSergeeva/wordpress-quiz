<?php
declare(strict_types=1);
namespace WordPressQuiz;

function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wpq_leads';
}

function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        request_id varchar(36) NOT NULL,
        payload_hash varchar(64) NOT NULL,
        receipt varchar(36) NOT NULL,
        quiz_id bigint(20) unsigned NOT NULL,
        payload longtext NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int unsigned NOT NULL DEFAULT 0,
        crm_id varchar(100) NOT NULL DEFAULT '',
        last_error varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY request_id (request_id),
        KEY status (status)
    ) $charset;" );
    update_option( 'wpq_schema_version', VERSION );
}

/** Validate a bounded, stable-ID quiz schema. Never accept arbitrary HTML. */
function validate_questions( mixed $input ): array|\WP_Error {
    if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) < 1 || count( $input ) > 30 ) {
        return new \WP_Error( 'schema', 'Добавьте от 1 до 30 вопросов.' );
    }
    $questions = []; $ids = [];
    foreach ( $input as $question ) {
        if ( ! is_array( $question ) ) {
            return new \WP_Error( 'schema', 'Некорректный вопрос.' );
        }
        $id = $question['id'] ?? '';
        $title = $question['title'] ?? '';
        $options = $question['options'] ?? null;
        if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $id ) || isset( $ids[$id] ) || ! is_string( $title ) || ! trim( $title ) || mb_strlen( $title ) > 250 || ! is_array( $options ) || ! array_is_list( $options ) || count( $options ) < 2 || count( $options ) > 20 ) {
            return new \WP_Error( 'schema', 'У каждого вопроса нужны уникальный ID, текст до 250 символов и от 2 до 20 вариантов.' );
        }
        $ids[$id] = true; $clean_options = []; $option_ids = [];
        foreach ( $options as $option ) {
            $oid = is_array( $option ) ? ( $option['id'] ?? '' ) : '';
            $label = is_array( $option ) ? ( $option['label'] ?? '' ) : '';
            if ( ! is_string( $oid ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $oid ) || isset( $option_ids[$oid] ) || ! is_string( $label ) || ! trim( $label ) || mb_strlen( $label ) > 200 ) {
                return new \WP_Error( 'schema', 'У каждого варианта нужны уникальный ID и текст до 200 символов.' );
            }
            $option_ids[$oid] = true;
            $clean_options[] = [ 'id' => $oid, 'label' => sanitize_text_field( $label ) ];
        }
        $questions[] = [ 'id' => $id, 'title' => sanitize_text_field( $title ), 'options' => $clean_options ];
    }
    return $questions;
}

function revision( int $id, array $questions ): string {
    return hash( 'sha256', wp_json_encode( [ $id, get_the_title( $id ), $questions ] ) );
}

function api_error( string $message, int $status = 400 ): \WP_Error {
    return new \WP_Error( 'wpq_error', $message, [ 'status' => $status ] );
}

add_action( 'rest_api_init', static function () {
    register_rest_route( 'wordpress-quiz/v1', '/leads', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\submit_lead',
    ] );
} );

function submit_lead( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;
    if ( strlen( $request->get_body() ) > 16000 ) {
        return api_error( 'Слишком большой запрос.', 413 );
    }
    // The public form does not use an anonymous WordPress nonce as anti-spam protection.
    $origin = $request->get_header( 'origin' );
    if ( $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
        return api_error( 'Отправьте форму с сайта.', 403 );
    }
    $data = $request->get_json_params();
    if ( ! is_array( $data ) ) { return api_error( 'Ожидается JSON.' ); }
    foreach ( [ 'requestId', 'revision', 'name', 'email', 'phone', 'website' ] as $key ) {
        if ( isset( $data[$key] ) && ! is_string( $data[$key] ) ) { return api_error( 'Некорректные поля.' ); }
    }
    if ( ! empty( $data['website'] ) ) { return api_error( 'Не удалось отправить форму.' ); }
    $request_id = $data['requestId'] ?? '';
    if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $request_id ) ) {
        return api_error( 'Некорректный идентификатор отправки.' );
    }
    if ( ! isset( $data['quizId'] ) || ! is_int( $data['quizId'] ) ) { return api_error( 'Не указан квиз.' ); }
    $id = $data['quizId'];
    $post = get_post( $id );
    if ( ! $post || 'wpq_quiz' !== $post->post_type || 'publish' !== $post->post_status ) {
        return api_error( 'Квиз недоступен.', 404 );
    }
    $questions = validate_questions( get_post_meta( $id, '_wpq_questions', true ) );
    if ( is_wp_error( $questions ) ) { return api_error( 'Квиз пока не настроен.', 409 ); }
    if ( ! hash_equals( revision( $id, $questions ), $data['revision'] ?? '' ) ) {
        return api_error( 'Вопросы изменились. Обновите страницу и пройдите квиз заново.', 409 );
    }
    $name = sanitize_text_field( trim( $data['name'] ?? '' ) );
    $email = trim( $data['email'] ?? '' );
    $phone = trim( $data['phone'] ?? '' );
    if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 || strlen( $email ) > 254 || ! is_email( $email ) || ( $phone && ! preg_match( '/^\+?[0-9 ()-]{7,30}$/D', $phone ) ) ) {
        return api_error( 'Проверьте имя, email и телефон.' );
    }
    if ( true !== ( $data['consent'] ?? false ) ) { return api_error( 'Необходимо согласие на обработку данных.' ); }
    $answers = $data['answers'] ?? null;
    if ( ! is_array( $answers ) || count( $answers ) !== count( $questions ) ) { return api_error( 'Ответьте на все вопросы.' ); }
    $snapshot = [];
    foreach ( $questions as $question ) {
        $selected = $answers[$question['id']] ?? null;
        $matched = null;
        foreach ( $question['options'] as $option ) {
            if ( $option['id'] === $selected ) { $matched = $option; break; }
        }
        if ( ! $matched ) { return api_error( 'Выбран недопустимый вариант ответа.' ); }
        $snapshot[] = [ 'questionId' => $question['id'], 'question' => $question['title'], 'answerId' => $matched['id'], 'answer' => $matched['label'] ];
    }
    $payload = [
        'requestId' => $request_id, 'quizId' => $id, 'quizTitle' => $post->post_title,
        'revision' => $data['revision'], 'contact' => [ 'name' => $name, 'email' => sanitize_email( $email ), 'phone' => $phone ],
        'answers' => $snapshot, 'consent' => [ 'accepted' => true, 'version' => CONSENT_VERSION ],
    ];
    $hash = hash( 'sha256', wp_json_encode( $payload ) );
    $table = table();
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE request_id = %s", $request_id ) );
    if ( $existing ) {
        return hash_equals( $existing->payload_hash, $hash ) ? receipt( $existing ) : api_error( 'Эта отправка уже использована. Обновите страницу.', 409 );
    }
    $rate_key = 'wpq_rate_' . hash_hmac( 'sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', wp_salt() );
    $count = (int) get_transient( $rate_key );
    if ( $count >= 10 ) { return api_error( 'Слишком много отправок. Попробуйте через 10 минут.', 429 ); }
    set_transient( $rate_key, $count + 1, 10 * MINUTE_IN_SECONDS );
    $payload['createdAt'] = gmdate( 'c' );
    $payload['consent']['acceptedAt'] = $payload['createdAt'];
    $now = current_time( 'mysql', true );
    // A unique DB key prevents concurrent duplicate submissions.
    $suppress = $wpdb->suppress_errors( true );
    $saved = $wpdb->insert( $table, [
        'request_id' => $request_id, 'payload_hash' => $hash, 'receipt' => wp_generate_uuid4(), 'quiz_id' => $id,
        'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), 'created_at' => $now, 'updated_at' => $now,
    ] );
    $wpdb->suppress_errors( $suppress );
    if ( ! $saved ) {
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE request_id = %s", $request_id ) );
        if ( $existing && hash_equals( $existing->payload_hash, $hash ) ) { return receipt( $existing ); }
        return api_error( 'Заявка не сохранена. Попробуйте ещё раз.', 503 );
    }
    $lead_id = (int) $wpdb->insert_id;
    deliver( $lead_id );
    return receipt( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $lead_id ) ), 201 );
}

function receipt( object $lead, int $code = 200 ): \WP_REST_Response {
    $response = new \WP_REST_Response( [ 'accepted' => true, 'receipt' => $lead->receipt ], $code );
    $response->header( 'Cache-Control', 'no-store' );
    return $response;
}

function deliver( int $id ): bool {
    global $wpdb;
    $table = table();
    $now = current_time( 'mysql', true );
    $stale = gmdate( 'Y-m-d H:i:s', time() - 300 );
    $locked = $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET status = 'sending', attempts = attempts + 1, updated_at = %s WHERE id = %d AND (status IN ('pending','failed') OR (status = 'sending' AND updated_at < %s))", $now, $id, $stale
    ) );
    if ( ! $locked ) { return false; }
    $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    $url = defined( 'WPQ_CRM_URL' ) ? WPQ_CRM_URL : getenv( 'WPQ_CRM_URL' );
    $token = defined( 'WPQ_CRM_TOKEN' ) ? WPQ_CRM_TOKEN : getenv( 'WPQ_CRM_TOKEN' );
    $error = ''; $crm_id = '';
    if ( ! $url || ! $token ) {
        $error = 'CRM не настроена на сервере.';
    } else {
        // URL is trusted server configuration, never accepted from a visitor.
        $response = wp_remote_post( $url, [ 'timeout' => 8, 'redirection' => 0,
            'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token, 'Idempotency-Key' => $lead->request_id ],
            'body' => $lead->payload,
        ] );
        if ( is_wp_error( $response ) ) {
            $error = 'Ошибка соединения с CRM.';
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['id'] ) && is_scalar( $body['id'] ) ) {
                $crm_id = substr( sanitize_text_field( (string) $body['id'] ), 0, 100 );
            } else { $error = 'CRM не подтвердила получение (HTTP ' . $code . ').'; }
        }
    }
    $wpdb->update( $table, [ 'status' => $error ? 'failed' : 'delivered', 'crm_id' => $crm_id, 'last_error' => $error, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
    return ! $error;
}
