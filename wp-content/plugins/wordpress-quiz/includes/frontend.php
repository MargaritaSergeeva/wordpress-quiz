<?php
declare(strict_types=1);
namespace WordPressQuiz;

function register_assets(): void
{
    $base = plugins_url('../', __FILE__);
    wp_register_style('wpq-font', $base . 'assets/fonts.css', [], VERSION);
    wp_register_style('wpq-view', $base . 'assets/view.css', ['wpq-font'], VERSION);
    wp_register_script(
        'wpq-block-editor',
        $base . 'assets/block-editor.js',
        ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch'],
        VERSION,
        true,
    );
    wp_register_script_module(
        'wpq-view',
        $base . 'assets/view.js',
        ['@wordpress/interactivity'],
        VERSION,
    );
    wp_interactivity_state('wordpress-quiz', [
        'stepHidden' => static function () {
            $c = wp_interactivity_get_context();
            return ($c['step'] ?? 0) !== ($c['index'] ?? 0);
        },
        'contactHidden' => static function () {
            $c = wp_interactivity_get_context();
            return ($c['step'] ?? 0) !== ($c['total'] ?? 0);
        },
        'nextHidden' => static function () {
            $c = wp_interactivity_get_context();
            return ($c['step'] ?? 0) === ($c['total'] ?? 0);
        },
        'backHidden' => static fn() => 0 === (wp_interactivity_get_context()['step'] ?? 0),
        'stepLabel' => static function () {
            $c = wp_interactivity_get_context();
            return 'Шаг ' . (($c['step'] ?? 0) + 1) . ' из ' . (($c['total'] ?? 0) + 1);
        },
        'progress' => static fn() => (wp_interactivity_get_context()['step'] ?? 0) + 1,
        'submitLabel' => 'Отправить заявку',
    ]);
}

function render_quiz(int $id): string
{
    $post = get_post($id);
    $questions = validate_questions(get_post_meta($id, '_wpq_questions', true));
    if (
        !$post ||
        'wpq_quiz' !== $post->post_type ||
        'publish' !== $post->post_status ||
        is_wp_error($questions)
    ) {
        return current_user_can('edit_posts')
            ? '<p>Выберите опубликованный квиз с заполненными вопросами.</p>'
            : '';
    }
    wp_enqueue_style('wpq-view');
    wp_enqueue_script_module('wpq-view');
    $uid = wp_unique_id('wpq-');
    $context = [
        'quizId' => $id,
        'revision' => revision($id, $questions),
        'total' => count($questions),
        'step' => 0,
        'answers' => (object) [],
        'requestId' => '',
        'busy' => false,
        'success' => false,
        'error' => '',
        'receipt' => '',
        'endpoint' => rest_url('wordpress-quiz/v1/leads'),
    ];
    ob_start();
    ?>
<section
    class="wpq"
    aria-labelledby="<?php echo esc_attr($uid); ?>title"
    data-wp-interactive="wordpress-quiz"
    <?php echo wp_interactivity_data_wp_context($context); ?>
>
    <div class="wpq-intro">
        <p class="wpq-eyebrow">ВАШ СЛЕДУЮЩИЙ ШАГ</p>
        <h2 id="<?php echo esc_attr($uid); ?>title"><?php echo esc_html($post->post_title); ?></h2>
        <p>Несколько коротких вопросов — и мы лучше поймём вашу задачу.</p>
        <p class="wpq-demo">Демонстрация: используйте вымышленные контакты.</p>
    </div>
    <div class="wpq-card">
        <div data-wp-bind--hidden="context.success">
            <div class="wpq-progress-label">
                <span data-wp-text="state.stepLabel">Шаг 1 из <?php echo count($questions) +
                    1; ?></span>
                <span>Около 2 минут</span>
            </div>
            <progress
                class="wpq-progress"
                max="<?php echo count($questions) + 1; ?>"
                value="1"
                data-wp-bind--value="state.progress"
            >
                Прогресс
            </progress>
            <form
                data-wp-on--submit="actions.submit"
                novalidate
            >
                <?php foreach ($questions as $index => $question): ?>
                <fieldset
                    class="wpq-step"
                    data-wp-context="<?php echo esc_attr(wp_json_encode(['index' => $index])); ?>"
                    data-wp-bind--hidden="state.stepHidden"
                    <?php echo $index ? 'hidden' : ''; ?>
                >
                    <legend tabindex="-1"><?php echo esc_html($question['title']); ?></legend>
                    <div class="wpq-options">
                        <?php foreach ($question['options'] as $option): ?>
                        <label class="wpq-option">
                            <input
                                type="radio"
                                name="<?php echo esc_attr($uid . $question['id']); ?>"
                                value="<?php echo esc_attr($option['id']); ?>"
                                data-question="<?php echo esc_attr($question['id']); ?>"
                                data-wp-on--change="actions.choose"
                            />
                            <span><?php echo esc_html($option['label']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php endforeach; ?>
                <fieldset
                    class="wpq-contact"
                    hidden
                    data-wp-bind--hidden="state.contactHidden"
                >
                    <legend tabindex="-1">Куда отправить предложение?</legend>
                    <p>Оставьте контакты. Ответы на вопросы будут приложены к заявке.</p>
                    <label>
                        Имя
                        <span aria-hidden="true">*</span>
                        <input
                            name="name"
                            autocomplete="name"
                            minlength="2"
                            maxlength="100"
                            required
                            placeholder="Анна"
                        />
                    </label>
                    <label>
                        Email
                        <span aria-hidden="true">*</span>
                        <input
                            name="email"
                            type="email"
                            autocomplete="email"
                            maxlength="254"
                            required
                            placeholder="anna@example.test"
                        />
                    </label>
                    <label>
                        Телефон
                        <span class="wpq-muted">необязательно</span>
                        <input
                            name="phone"
                            type="tel"
                            autocomplete="tel"
                            maxlength="30"
                            placeholder="+7 (000) 000-00-00"
                        />
                    </label>
                    <div
                        class="wpq-trap"
                        aria-hidden="true"
                    >
                        <label>
                            Сайт
                            <input
                                name="website"
                                tabindex="-1"
                                autocomplete="off"
                            />
                        </label>
                    </div>
                    <label class="wpq-consent">
                        <input
                            name="consent"
                            type="checkbox"
                            required
                        />
                        <span>
                            Согласен на обработку введённых данных для демонстрации отправки заявки.
                            <a
                                href="<?php echo esc_url(home_url('/demo-data/')); ?>"
                                target="_blank"
                                rel="noopener"
                            >
                                Условия обработки данных
                            </a>
                        </span>
                    </label>
                </fieldset>
                <p
                    class="wpq-error"
                    role="alert"
                    data-wp-text="context.error"
                ></p>
                <div class="wpq-actions">
                    <button
                        type="button"
                        class="wpq-back"
                        hidden
                        data-wp-bind--hidden="state.backHidden"
                        data-wp-bind--disabled="context.busy"
                        data-wp-on--click="actions.back"
                    >
                        Назад
                    </button>
                    <button
                        type="button"
                        class="wpq-primary"
                        data-wp-bind--hidden="state.nextHidden"
                        data-wp-on--click="actions.next"
                    >
                        Далее
                        <span aria-hidden="true">→</span>
                    </button>
                    <button
                        type="submit"
                        class="wpq-primary"
                        hidden
                        data-wp-bind--hidden="state.contactHidden"
                        data-wp-bind--disabled="context.busy"
                        data-wp-text="state.submitLabel"
                    >
                        Отправить заявку
                    </button>
                </div>
            </form>
        </div>
        <div
            class="wpq-success"
            hidden
            data-wp-bind--hidden="!context.success"
        >
            <span
                class="wpq-check"
                aria-hidden="true"
            >
                ✓
            </span>
            <h3 tabindex="-1">Заявка получена</h3>
            <p>Контакты и ваши ответы сохранены. Спасибо за прохождение квиза!</p>
            <p class="wpq-muted">
                Номер:
                <span data-wp-text="context.receipt"></span>
            </p>
        </div>
        <noscript>Для прохождения квиза включите JavaScript в браузере.</noscript>
    </div>
</section>
<?php return (string) ob_get_clean();
}
