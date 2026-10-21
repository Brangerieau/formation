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
            // Server-rendered with the same partial as the history: tool calls, then the answer.
            this.logTarget.insertAdjacentHTML('beforeend', await response.text());
            this.logTarget.scrollTop = this.logTarget.scrollHeight;
        } catch {
            this.#append('error', 'Erreur, réessayez.');
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
    }
}
