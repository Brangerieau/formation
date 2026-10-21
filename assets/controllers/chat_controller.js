import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['log', 'input'];
    static values = { url: String, resetUrl: String };

    async send(event) {
        event.preventDefault();
        const message = this.inputTarget.value.trim();
        if (!message) {
            return;
        }
        // One message at a time: the next one is sent with the history this one completes.
        const controls = [...event.currentTarget.elements];
        controls.forEach((control) => (control.disabled = true));
        this.inputTarget.value = '';
        this.#append('user', message);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            let answer = null;
            let done = false;
            for await (const { type, data } of events(response)) {
                if ('tool' === type) {
                    // Server-rendered with the same partial as the history.
                    this.logTarget.insertAdjacentHTML('beforeend', data);
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
        } catch {
            this.#append('error', 'Erreur, réessayez.');
        } finally {
            controls.forEach((control) => (control.disabled = false));
            this.inputTarget.focus();
        }
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
