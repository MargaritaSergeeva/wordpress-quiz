import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

const focusStep = (root, selector) =>
    requestAnimationFrame(() => root.querySelector(selector)?.focus());
const rootOf = () => getElement().ref.closest('.wpq');

store('wordpress-quiz', {
    state: {
        get stepHidden() {
            const c = getContext();
            return c.step !== c.index;
        },
        get contactHidden() {
            const c = getContext();
            return c.step !== c.total;
        },
        get nextHidden() {
            const c = getContext();
            return c.step === c.total;
        },
        get backHidden() {
            return getContext().step === 0;
        },
        get stepLabel() {
            const c = getContext();
            return `Шаг ${c.step + 1} из ${c.total + 1}`;
        },
        get progress() {
            return getContext().step + 1;
        },
        get submitLabel() {
            return getContext().busy ? 'Отправляем…' : 'Отправить заявку';
        },
    },
    actions: {
        choose: withSyncEvent((event) => {
            const c = getContext();
            c.answers[event.target.dataset.question] = event.target.value;
            c.error = '';
            c.requestId = '';
        }),
        next() {
            const c = getContext();
            const root = rootOf();
            if (!root.querySelectorAll('.wpq-step')[c.step]?.querySelector('input:checked')) {
                c.error = 'Выберите один вариант ответа.';
                root.querySelectorAll('.wpq-step')[c.step]?.querySelector('input')?.focus();
                return;
            }
            c.error = '';
            c.step = Math.min(c.total, c.step + 1);
            focusStep(root, 'fieldset:not([hidden]) legend');
        },
        back() {
            const c = getContext();
            if (c.busy) return;
            c.error = '';
            c.step = Math.max(0, c.step - 1);
            focusStep(rootOf(), 'fieldset:not([hidden]) legend');
        },
        submit: withSyncEvent(async (event) => {
            event.preventDefault();
            const c = getContext();
            if (c.busy || c.success || c.step !== c.total) return;
            const form = event.target;
            const root = form.closest('.wpq');
            if (!form.reportValidity()) return;
            const fields = new FormData(form);
            const content = {
                quizId: c.quizId,
                revision: c.revision,
                answers: { ...c.answers },
                name: fields.get('name').trim(),
                email: fields.get('email').trim(),
                phone: fields.get('phone').trim(),
                consent: fields.get('consent') === 'on',
                website: fields.get('website'),
            };
            // Reuse the key after a network error if the user retries identical data.
            const fingerprint = JSON.stringify(content);
            if (!c.requestId || c.fingerprint !== fingerprint) {
                c.requestId = crypto.randomUUID();
                c.fingerprint = fingerprint;
            }
            c.busy = true;
            c.error = '';
            try {
                const response = await fetch(c.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...content, requestId: c.requestId }),
                    signal: AbortSignal.timeout(20000),
                });
                const result = await response.json();
                if (!response.ok || !result.accepted)
                    throw new Error(
                        result.message || 'Не удалось отправить заявку. Повторите попытку.'
                    );
                c.receipt = result.receipt;
                c.success = true;
                focusStep(root, '.wpq-success h3');
            } catch (error) {
                c.error =
                    error.name === 'TimeoutError' || error instanceof TypeError
                        ? 'Нет ответа от сервера. Проверьте соединение и повторите отправку — ответы сохранены в форме.'
                        : error.message;
            } finally {
                c.busy = false;
            }
        }),
    },
});
