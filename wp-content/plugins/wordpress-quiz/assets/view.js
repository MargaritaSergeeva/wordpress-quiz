import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

const focusStep = (root, selector) =>
    requestAnimationFrame(() => root.querySelector(selector)?.focus());
const rootOf = () => getElement().ref.closest('.wpq');

const phoneError = 'Введите российский номер полностью: +7 (999) 123-45-67.';

function formatPhone(input) {
    const raw = input.value;
    const caret = input.selectionStart ?? raw.length;
    let digits = raw.replace(/\D/g, '');
    let digitsBefore = raw.slice(0, caret).replace(/\D/g, '').length;
    if (raw.startsWith('+7') || (digits.length === 11 && /^[78]/.test(digits))) {
        digits = digits.slice(1);
        digitsBefore = Math.max(0, digitsBefore - 1);
    }
    // Do not silently shorten an invalid pasted number or discard letters.
    if (
        /[^+\d\s()-]/.test(raw) ||
        digits.length > 10 ||
        (raw.startsWith('+') && !raw.startsWith('+7'))
    ) {
        input.setCustomValidity(phoneError);
        return;
    }
    let formatted = '+7';
    if (digits.length) formatted += ' (' + digits.slice(0, 3);
    if (digits.length >= 3) formatted += ') ';
    if (digits.length > 3) formatted += digits.slice(3, 6);
    if (digits.length > 6) formatted += '-' + digits.slice(6, 8);
    if (digits.length > 8) formatted += '-' + digits.slice(8, 10);
    input.value = formatted;
    const positions = [...formatted.matchAll(/\d/g)].map((match) => match.index + 1);
    const nextCaret = caret === raw.length ? formatted.length : (positions[digitsBefore] ?? 2);
    input.setSelectionRange(nextCaret, nextCaret);
    input.setCustomValidity(!digits.length || /^[3489]\d{9}$/.test(digits) ? '' : phoneError);
}

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
        phoneFocus: withSyncEvent((event) => {
            if (!event.target.value) event.target.value = '+7';
        }),
        phoneInput: withSyncEvent((event) => {
            formatPhone(event.target);
        }),
        phonePaste: withSyncEvent((event) => {
            event.preventDefault();
            event.target.value = event.clipboardData.getData('text').trim();
            formatPhone(event.target);
        }),
        phoneBlur: withSyncEvent((event) => {
            if (event.target.value === '+7') event.target.value = '';
        }),
        phoneKeydown: withSyncEvent((event) => {
            const input = event.target;
            let start = input.selectionStart;
            let end = input.selectionEnd;
            if (start !== end || event.ctrlKey || event.metaKey || event.altKey) return;
            // Skip mask punctuation so Backspace/Delete always remove a digit.
            if (event.key === 'Backspace') {
                while (start > 2 && /\D/.test(input.value[start - 1])) start--;
                if (start <= 2) event.preventDefault();
                else input.setSelectionRange(start, start);
            } else if (event.key === 'Delete') {
                while (end < input.value.length && /\D/.test(input.value[end])) end++;
                if (end < 2) event.preventDefault();
                else input.setSelectionRange(end, end);
            }
        }),
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
                phone:
                    fields.get('phone').replace(/\D/g, '').length > 1
                        ? '+' + fields.get('phone').replace(/\D/g, '')
                        : '',
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
