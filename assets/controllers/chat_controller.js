import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['log', 'input'];
    static values = { url: String };

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
            this.#append('bot', (await response.json()).reply);
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
