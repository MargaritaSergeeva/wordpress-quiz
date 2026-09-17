(() => {
    const mount = document.getElementById('wpq-editor');
    const source = document.getElementById('wpq-schema');
    if (!mount || !source) return;
    let questions = JSON.parse(source.value || '[]');
    const uid = () => crypto.randomUUID();
    const sync = () => {
        source.value = JSON.stringify(questions);
    };
    const button = (text, callback, disabled = false) => {
        const node = document.createElement('button');
        node.type = 'button';
        node.className = 'button';
        node.textContent = text;
        node.disabled = disabled;
        node.addEventListener('click', callback);
        return node;
    };
    const field = (label, value, max, callback) => {
        const wrap = document.createElement('label');
        wrap.textContent = label;
        const input = document.createElement('input');
        input.type = 'text';
        input.value = value;
        input.required = true;
        input.maxLength = max;
        input.addEventListener('input', () => {
            callback(input.value);
            sync();
        });
        wrap.append(input);
        return wrap;
    };
    function render() {
        mount.replaceChildren();
        questions.forEach((q, index) => {
            const card = document.createElement('section');
            card.className = 'wpq-editor-question';
            const heading = document.createElement('h3');
            heading.textContent = `Вопрос ${index + 1}`;
            card.append(
                heading,
                field('Текст вопроса', q.title, 250, (v) => {
                    q.title = v;
                })
            );
            const move = (offset) => {
                [questions[index], questions[index + offset]] = [
                    questions[index + offset],
                    questions[index],
                ];
                render();
            };
            const controls = document.createElement('div');
            controls.className = 'wpq-editor-controls';
            controls.append(
                button('Выше', () => move(-1), index === 0),
                button('Ниже', () => move(1), index === questions.length - 1),
                button('Удалить вопрос', () => {
                    questions.splice(index, 1);
                    render();
                })
            );
            card.append(controls);
            q.options.forEach((option, oi) => {
                const row = document.createElement('div');
                row.className = 'wpq-editor-option';
                row.append(
                    field(`Вариант ${oi + 1}`, option.label, 200, (v) => {
                        option.label = v;
                    }),
                    button(
                        'Удалить',
                        () => {
                            q.options.splice(oi, 1);
                            render();
                        },
                        q.options.length <= 2
                    )
                );
                card.append(row);
            });
            card.append(
                button(
                    'Добавить вариант',
                    () => {
                        q.options.push({ id: uid(), label: '' });
                        render();
                    },
                    q.options.length >= 20
                )
            );
            mount.append(card);
        });
        mount.append(
            button(
                'Добавить вопрос',
                () => {
                    questions.push({
                        id: uid(),
                        title: '',
                        options: [
                            { id: uid(), label: '' },
                            { id: uid(), label: '' },
                        ],
                    });
                    render();
                    mount.querySelector('.wpq-editor-question:last-of-type input')?.focus();
                },
                questions.length >= 30
            )
        );
        sync();
    }
    render();
    document.getElementById('post')?.addEventListener('submit', (event) => {
        if (
            !questions.length ||
            questions.some((q) => !q.title.trim() || q.options.some((o) => !o.label.trim()))
        ) {
            event.preventDefault();
            alert('Добавьте хотя бы один вопрос и заполните тексты вопросов и вариантов.');
        }
    });
})();
