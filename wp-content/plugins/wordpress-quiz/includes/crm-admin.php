<?php
declare(strict_types=1);
namespace WordPressQuiz;

add_action('admin_menu', static function () {
    add_submenu_page(
        'edit.php?post_type=wpq_quiz',
        'Тестовая CRM',
        'Тестовая CRM',
        'manage_options',
        'wpq-crm',
        __NAMESPACE__ . '\\crm_page',
    );
});

/** Read the receiver itself. Never substitute the WordPress outbox for CRM data. */
function crm_records(): array|\WP_Error
{
    $url = defined('WPQ_CRM_URL') ? WPQ_CRM_URL : getenv('WPQ_CRM_URL');
    $token = defined('WPQ_CRM_TOKEN') ? WPQ_CRM_TOKEN : getenv('WPQ_CRM_TOKEN');
    if (!$url || !$token) {
        return new \WP_Error('crm_config', 'Подключение к тестовой CRM не настроено.');
    }

    $response = wp_remote_get($url, [
        'timeout' => 8,
        'redirection' => 0,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
        ],
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return new \WP_Error(
            'crm_unavailable',
            'Не удалось прочитать данные из CRM. Попробуйте обновить таблицу позже.',
        );
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (
        !is_array($body) ||
        !isset($body['leads']) ||
        !is_array($body['leads']) ||
        !array_is_list($body['leads'])
    ) {
        return new \WP_Error('crm_response', 'CRM вернула ответ в неизвестном формате.');
    }
    foreach ($body['leads'] as $row) {
        if (
            !is_array($row) ||
            !is_string($row['id'] ?? null) ||
            !is_array($row['payload'] ?? null)
        ) {
            return new \WP_Error('crm_response', 'CRM вернула ответ в неизвестном формате.');
        }
    }
    return $body['leads'];
}

/** Escape external CRM values without trusting their type or HTML. */
function crm_text(mixed $value): string
{
    return esc_html(is_scalar($value) ? (string) $value : '—');
}

function crm_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав.', '', ['response' => 403]);
    }
    $rows = crm_records();
    ?>
    <div class="wrap">
        <h1>Тестовая CRM — полученные заявки</h1>
        <p>Данные загружены напрямую из отдельного CRM-приёмника. Здесь показаны только записи, которые действительно сохранены в CRM.</p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(
                admin_url('edit.php?post_type=wpq_quiz&page=wpq-crm'),
            ); ?>">Обновить таблицу</a>
            <a class="button" href="<?php echo esc_url(
                admin_url('edit.php?post_type=wpq_quiz&page=wpq-leads'),
            ); ?>">Заявки в WordPress и статус доставки</a>
        </p>
        <?php if (is_wp_error($rows)): ?>
            <div class="notice notice-error inline"><p><?php echo esc_html(
                $rows->get_error_message(),
            ); ?></p></div>
        <?php else: ?>
            <p>Получено записей: <strong><?php echo count(
                $rows,
            ); ?></strong>. Показаны последние 100, новые сверху. Обновлено: <?php echo esc_html(
    wp_date('d.m.Y H:i:s'),
); ?> (часовой пояс сайта).</p>
            <div style="overflow-x: auto;">
                <table class="widefat striped" style="min-width: 760px;">
                    <thead>
                        <tr>
                            <th scope="col">Квиз / время отправки (UTC)</th>
                            <th scope="col">Контакты</th>
                            <th scope="col">Полученные вопросы и ответы</th>
                            <th scope="col">Идентификаторы</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):

                            $payload = $row['payload'];
                            $contact = is_array($payload['contact'] ?? null)
                                ? $payload['contact']
                                : [];
                            $answers = is_array($payload['answers'] ?? null)
                                ? $payload['answers']
                                : [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo crm_text(
                                        $payload['quizTitle'] ?? null,
                                    ); ?></strong><br>
                                    <?php echo crm_text($payload['createdAt'] ?? null); ?>
                                </td>
                                <td>
                                    <?php echo crm_text($contact['name'] ?? null); ?><br>
                                    <?php echo crm_text($contact['email'] ?? null); ?><br>
                                    <?php echo crm_text(
                                        $contact['phone'] ?? '' ?: 'Телефон не указан',
                                    ); ?>
                                </td>
                                <td>
                                    <ol>
                                        <?php foreach ($answers as $answer): ?>
                                            <li>
                                                <strong><?php echo crm_text(
                                                    is_array($answer)
                                                        ? $answer['question'] ?? null
                                                        : null,
                                                ); ?></strong><br>
                                                <?php echo crm_text(
                                                    is_array($answer)
                                                        ? $answer['answer'] ?? null
                                                        : null,
                                                ); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </td>
                                <td style="overflow-wrap: anywhere; max-width: 260px;">
                                    <strong>ID в CRM</strong><br><?php echo crm_text(
                                        $row['id'],
                                    ); ?><br>
                                    <strong>ID отправки</strong><br><?php echo crm_text(
                                        $payload['requestId'] ?? null,
                                    ); ?>
                                </td>
                            </tr>
                        <?php
                        endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="4">В CRM пока нет заявок. Пройдите квиз с вымышленными контактами, затем обновите таблицу.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
