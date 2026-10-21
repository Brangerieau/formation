import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['log', 'input', 'form'];
    static values = { url: String, resetUrl: String, confirmUrl: String, cancelUrl: String };

    #running = false;

    async send(event) {
        event.preventDefault();
        const message = this.inputTarget.value.trim();
        if (!message) {
            return;
        }
        this.inputTarget.value = '';
        this.#append('user', message);
        await this.#stream(this.urlValue, JSON.stringify({ message }));
        this.inputTarget.focus();
    }

    async confirm() {
        await this.#stream(this.confirmUrlValue);
    }

    async cancel() {
        await this.#busy(async () => {
            const response = await fetch(this.cancelUrlValue, { method: 'POST' });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.#insert(await response.text());
        });
    }

    // The new tool calls come as server-rendered cards, then the answer as text pieces.
    async #stream(url, body) {
        await this.#busy(async () => {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            let answer = null;
            let done = false;
            for await (const { type, data } of events(response)) {
                if ('tool' === type) {
                    this.#insert(data);
                    answer = null;
                } else if ('delta' === type) {
                    answer ??= this.#append('assistant', '');
                    answer.textContent += data;
                } else if ('done' === type) {
                    done = true;
                } else if ('error' === type) {
                    throw new Error('LLM');
                }
                this.logTarget.scrollTop = this.logTarget.scrollHeight;
            }
            // A stream cut short (time limit, crash) ends without "done".
            if (!done) {
                throw new Error('Incomplete');
            }
        });
    }

    // One request at a time: the next one is sent with the history this one completes,
    // and a card to confirm can arrive before its stream ends.
    async #busy(request) {
        if (this.#running) {
            return;
        }
        this.#running = true;
        const controls = [...this.formTarget.elements];
        controls.forEach((control) => (control.disabled = true));
        try {
            await request();
        } catch {
            this.#append('error', 'Erreur, réessayez.');
        } finally {
            this.#running = false;
            controls.forEach((control) => (control.disabled = false));
        }
    }

    // A card for a call already shown, waiting for confirmation, replaces it.
    #insert(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        for (const card of [...template.content.children]) {
            const shown = card.id && this.logTarget.querySelector(`#${CSS.escape(card.id)}`);
            shown ? shown.replaceWith(card) : this.logTarget.append(card);
        }
        this.logTarget.scrollTop = this.logTarget.scrollHeight;
    }

    async reset() {
        try {
            const response = await fetch(this.resetUrlValue, { method: 'POST' });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.logTarget.replaceChildren();
        } catch {
            this.#append('error', 'Erreur, réessayez.');
        }
    }

    #append(role, text) {
        const p = document.createElement('p');
        p.className = `chat-msg chat-msg-${role}`;
        p.textContent = text;
        this.logTarget.append(p);
        this.logTarget.scrollTop = this.logTarget.scrollHeight;

        return p;
    }
}

// Server-sent events read from a fetch response: EventSource can't POST.
async function* events(response) {
    const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
    let buffer = '';
    for (;;) {
        const { value, done } = await reader.read();
        if (done) {
            return;
        }
        const blocks = (buffer + value).split('\n\n');
        buffer = blocks.pop();
        for (const block of blocks) {
            let type = 'message';
            const data = [];
            for (const line of block.split('\n')) {
                if (line.startsWith('event: ')) {
                    type = line.slice(7);
                } else if (line.startsWith('data: ')) {
                    data.push(line.slice(6));
                }
            }
            yield { type, data: data.join('\n') };
        }
    }
}
