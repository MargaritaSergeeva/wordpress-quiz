<?php
/** Idempotent demo content, intended for local development only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ( 'local' !== wp_get_environment_type() && '1' !== getenv( 'WPQ_ALLOW_DEMO_SETUP' ) ) ) { exit( 1 ); }

$definitions = [
    'marketing' => [
        'Подберём решение для вашего бизнеса',
        [
            [ 'goal', 'Какую задачу хотите решить?', [ 'leads' => 'Получать больше заявок', 'sales' => 'Увеличить продажи', 'start' => 'Запустить новый проект' ] ],
            [ 'channel', 'Что уже используете для продвижения?', [ 'ads' => 'Контекстную рекламу', 'seo' => 'SEO и контент', 'none' => 'Пока ничего' ] ],
            [ 'budget', 'Какой бюджет планируете в месяц?', [ 'small' => 'До 100 000 ₽', 'medium' => '100 000–300 000 ₽', 'large' => 'Более 300 000 ₽', 'help' => 'Нужна помощь с оценкой' ] ],
        ],
    ],
    'website' => [
        'Каким будет ваш новый сайт?',
        [
            [ 'type', 'Какой сайт вам нужен?', [ 'landing' => 'Лендинг', 'company' => 'Корпоративный сайт', 'shop' => 'Интернет-магазин' ] ],
            [ 'when', 'Когда хотите запустить проект?', [ 'soon' => 'В ближайший месяц', 'later' => 'Через 2–3 месяца', 'explore' => 'Пока изучаю варианты' ] ],
        ],
    ],
];
$ids = [];
foreach ( $definitions as $slug => [ $title, $rows ] ) {
    $id = (int) get_option( 'wpq_demo_' . $slug );
    if ( ! $id || ! get_post( $id ) ) {
        $id = wp_insert_post( [ 'post_type' => 'wpq_quiz', 'post_title' => $title, 'post_status' => 'publish' ], true );
        if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
        $questions = [];
        foreach ( $rows as [ $qid, $text, $options ] ) {
            $answers = [];
            foreach ( $options as $oid => $label ) { $answers[] = [ 'id' => $oid, 'label' => $label ]; }
            $questions[] = [ 'id' => $qid, 'title' => $text, 'options' => $answers ];
        }
        update_post_meta( $id, '_wpq_questions', $questions );
        update_option( 'wpq_demo_' . $slug, $id );
    }
    $ids[$slug] = $id;
}
$pages = [
    'demo-data' => [ 'Условия демонстрации', '<!-- wp:paragraph --><p>Это тестовый проект. Вводите только вымышленные имя, email и телефон. Форма сохраняет указанные контакты, ответы и время согласия в базе WordPress и передаёт их тестовому CRM-приёмнику. Данные доступны администратору проекта для проверки работы интеграции.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Это описание демонстрационного режима, а не готовая политика для коммерческого сайта. Перед приёмом реальных данных необходимо согласовать оператора, цели, основания, сроки хранения, порядок удаления и окончательные тексты документов.</p><!-- /wp:paragraph -->' ],
    'quiz-demo' => [ 'Квиз для вашего бизнеса', '<!-- wp:wordpress-quiz/quiz {"quizId":' . $ids['marketing'] . '} /-->' ],
    'two-quizzes' => [ 'Два независимых квиза', '<!-- wp:wordpress-quiz/quiz {"quizId":' . $ids['marketing'] . '} /--><!-- wp:shortcode -->[wordpress_quiz id="' . $ids['website'] . '"]<!-- /wp:shortcode -->' ],
];
foreach ( $pages as $slug => [ $title, $content ] ) {
    $page = get_page_by_path( $slug );
    if ( ! $page ) {
        $id = wp_insert_post( [ 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ], true );
        if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
    } else { $id = $page->ID; }
    if ( 'quiz-demo' === $slug ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $id );
    }
}
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blog_public', 0 );
flush_rewrite_rules();
switch_theme( 'quiz-demo' );
WP_CLI::success( 'Два квиза и демонстрационные страницы готовы.' );
