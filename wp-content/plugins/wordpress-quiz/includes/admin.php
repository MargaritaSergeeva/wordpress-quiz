<?php
declare(strict_types=1);
namespace WordPressQuiz;

add_action( 'add_meta_boxes_wpq_quiz', static function () {
    add_meta_box( 'wpq_questions', 'Вопросы и варианты ответов', __NAMESPACE__ . '\\editor', 'wpq_quiz', 'normal', 'high' );
} );

function editor( \WP_Post $post ): void {
    wp_nonce_field( 'wpq_save', 'wpq_nonce' );
    $questions = get_post_meta( $post->ID, '_wpq_questions', true );
    echo '<p>Добавляйте вопросы и варианты, меняйте порядок. Для каждого вопроса посетитель выбирает один ответ.</p>';
    echo '<div id="wpq-editor"></div><textarea hidden id="wpq-schema" name="wpq_schema">' . esc_textarea( wp_json_encode( is_array( $questions ) ? $questions : [] ) ) . '</textarea>';
    echo '<p>Вставка на страницу: блок <strong>«Квиз»</strong> или <code>[wordpress_quiz id="' . (int) $post->ID . '"]</code>.</p>';
}

add_action( 'admin_enqueue_scripts', static function () {
    $screen = get_current_screen();
    if ( $screen && 'wpq_quiz' === $screen->post_type && 'post' === $screen->base ) {
        wp_enqueue_script( 'wpq-admin', plugins_url( '../assets/admin.js', __FILE__ ), [], VERSION, true );
        wp_enqueue_style( 'wpq-admin', plugins_url( '../assets/admin.css', __FILE__ ), [], VERSION );
    }
} );

add_action( 'save_post_wpq_quiz', static function ( int $id ) {
    if ( wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['wpq_nonce'], $_POST['wpq_schema'] ) || ! is_string( $_POST['wpq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpq_nonce'] ) ), 'wpq_save' ) ) { return; }
    $raw = is_string( $_POST['wpq_schema'] ) ? wp_unslash( $_POST['wpq_schema'] ) : '';
    $questions = validate_questions( strlen( $raw ) <= 200000 ? json_decode( $raw, true ) : null );
    if ( is_wp_error( $questions ) ) {
        set_transient( 'wpq_notice_' . get_current_user_id(), $questions->get_error_message(), 60 );
        return;
    }
    update_post_meta( $id, '_wpq_questions', $questions );
}, 10, 1 );

add_action( 'admin_notices', static function () {
    $key = 'wpq_notice_' . get_current_user_id();
    $notice = get_transient( $key );
    if ( $notice ) {
        delete_transient( $key );
        echo '<div class="notice notice-error"><p>Вопросы не сохранены: ' . esc_html( $notice ) . ' Предыдущая версия сохранена.</p></div>';
    }
} );

add_action( 'admin_menu', static function () {
    add_submenu_page( 'edit.php?post_type=wpq_quiz', 'Заявки', 'Заявки', 'manage_options', 'wpq-leads', __NAMESPACE__ . '\\leads_page' );
} );

function leads_page(): void {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $table = table();
    $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ) );
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $labels = [ 'pending' => 'Ожидает отправки', 'sending' => 'Отправляется', 'failed' => 'Ошибка доставки', 'delivered' => 'Доставлена' ];
    echo '<div class="wrap"><h1>Заявки из квизов</h1><p>Здесь хранятся контакты и снимок ответов на момент отправки. Доступ разрешён только администратору.</p>';
    if ( isset( $_GET['retried'] ) ) { echo '<div class="notice notice-info"><p>Попытка доставки выполнена. Проверьте статус заявки.</p></div>'; }
    $configured = ( defined( 'WPQ_CRM_URL' ) || getenv( 'WPQ_CRM_URL' ) ) && ( defined( 'WPQ_CRM_TOKEN' ) || getenv( 'WPQ_CRM_TOKEN' ) );
    echo '<p><strong>CRM:</strong> ' . ( $configured ? 'подключение настроено на сервере' : 'не настроена — заявки будут сохранены с ошибкой доставки' ) . '</p>';
    echo '<table class="widefat striped"><thead><tr><th>Дата (UTC) / квиз</th><th>Контакты</th><th>Ответы</th><th>CRM</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        $payload = json_decode( $row->payload, true );
        echo '<tr><td>' . esc_html( $row->created_at ) . '<br><strong>' . esc_html( $payload['quizTitle'] ) . '</strong><br><small>' . esc_html( $row->request_id ) . '</small></td><td>';
        foreach ( $payload['contact'] as $value ) { echo esc_html( $value ) . '<br>'; }
        echo '<small>Согласие: ' . esc_html( $payload['consent']['version'] ) . '</small></td><td><ol>';
        foreach ( $payload['answers'] as $answer ) {
            echo '<li><strong>' . esc_html( $answer['question'] ) . '</strong><br>' . esc_html( $answer['answer'] ) . '</li>';
        }
        echo '</ol></td><td><strong>' . esc_html( $labels[$row->status] ?? $row->status ) . '</strong><br>Попыток: ' . (int) $row->attempts;
        if ( $row->crm_id ) { echo '<br>ID: ' . esc_html( $row->crm_id ); }
        if ( $row->last_error ) { echo '<br>' . esc_html( $row->last_error ); }
        if ( 'delivered' !== $row->status ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpq_retry"><input type="hidden" name="lead_id" value="' . (int) $row->id . '">';
            wp_nonce_field( 'wpq_retry_' . $row->id );
            submit_button( 'Повторить доставку', 'secondary small', 'submit', false );
            echo '</form>';
        }
        echo '</td></tr>';
    }
    if ( ! $rows ) { echo '<tr><td colspan="4">Заявок пока нет. Пройдите квиз на демонстрационной странице.</td></tr>'; }
    echo '</tbody></table><p>' . wp_kses_post( paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => max( 1, (int) ceil( $count / 20 ) ) ] ) ) . '</p></div>';
}

add_action( 'admin_post_wpq_retry', static function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', '', [ 'response' => 403 ] ); }
    $id = absint( $_POST['lead_id'] ?? 0 );
    check_admin_referer( 'wpq_retry_' . $id );
    deliver( $id );
    wp_safe_redirect( admin_url( 'edit.php?post_type=wpq_quiz&page=wpq-leads&retried=1' ) );
    exit;
} );
