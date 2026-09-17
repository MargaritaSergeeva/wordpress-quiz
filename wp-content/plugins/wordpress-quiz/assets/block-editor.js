(function (wp) {
    const el = wp.element.createElement;
    wp.blocks.registerBlockType('wordpress-quiz/quiz', {
        apiVersion: 3,
        title: 'Квиз',
        category: 'widgets',
        icon: 'forms',
        supports: { html: false },
        attributes: { quizId: { type: 'number', default: 0 } },
        edit({ attributes, setAttributes }) {
            const [quizzes, setQuizzes] = wp.element.useState([]);
            const [error, setError] = wp.element.useState('');
            wp.element.useEffect(() => {
                let alive = true;
                // Follow pagination so the selector still works with more than 100 quizzes.
                async function load() {
                    let page = 1,
                        all = [];
                    while (true) {
                        const response = await wp.apiFetch({
                            path: `/wp/v2/wpq_quiz?status=publish&per_page=100&page=${page}&context=edit`,
                            parse: false,
                        });
                        all = all.concat(await response.json());
                        if (page >= Number(response.headers.get('X-WP-TotalPages'))) break;
                        page++;
                    }
                    if (alive) setQuizzes(all);
                }
                load().catch(() => {
                    if (alive) setError('Не удалось загрузить квизы. Обновите редактор.');
                });
                return () => {
                    alive = false;
                };
            }, []);
            return el(
                'div',
                wp.blockEditor.useBlockProps({
                    style: { padding: '24px', border: '1px solid #d5d9e2', borderRadius: '12px' },
                }),
                el('strong', null, 'Пошаговый квиз'),
                el(wp.components.SelectControl, {
                    label: 'Опубликованный квиз',
                    value: attributes.quizId,
                    options: [
                        { label: 'Выберите квиз', value: 0 },
                        ...quizzes.map((q) => ({
                            label: q.title.raw || 'Без названия',
                            value: q.id,
                        })),
                    ],
                    onChange: (value) => setAttributes({ quizId: Number(value) }),
                }),
                error ? el('p', { role: 'alert' }, error) : null,
                el(
                    'p',
                    null,
                    'Вопросы редактируются в разделе «Квизы». На странице появится интерактивная форма.'
                )
            );
        },
        save() {
            return null;
        },
    });
})(window.wp);
