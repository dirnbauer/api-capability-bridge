const initSonarApiTester = () => {
    const root = document.getElementById('sonar-api-tester');
    if (!root || root.dataset.initialized === 'true') {
        return;
    }
    root.dataset.initialized = 'true';

    let examples = [];
    try {
        examples = JSON.parse(root.dataset.examples || '[]');
    } catch (error) {
        examples = [];
    }

    const list = document.getElementById('sonar-examples');
    const method = document.getElementById('sonar-method');
    const url = document.getElementById('sonar-url');
    const body = document.getElementById('sonar-body');
    const tenant = document.getElementById('sonar-tenant');
    const token = document.getElementById('sonar-token');
    const response = document.getElementById('sonar-response');
    const send = document.getElementById('sonar-send');

    if (!list || !method || !url || !body || !tenant || !token || !response || !send) {
        return;
    }

    const defaultTenant = root.dataset.defaultTenant || '';
    if (tenant.value.trim() === '' && defaultTenant !== '') {
        tenant.value = defaultTenant;
    }

    const applyExample = (example) => {
        method.value = example.method;
        url.value = example.url;
        body.value = example.body || '';
        response.textContent = `Prepared ${example.method} ${example.url}`;
    };

    list.replaceChildren();
    examples.forEach((example, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action text-start';
        button.textContent = `${example.method} ${example.label}`;
        button.addEventListener('click', () => applyExample(example));
        list.appendChild(button);
        if (index === 0) {
            applyExample(example);
        }
    });

    send.addEventListener('click', async () => {
        const requestBody = body.value.trim();
        const requestMethod = method.value;
        const requestUrl = url.value.trim();
        const headers = {
            Accept: 'application/json',
        };

        if (requestBody !== '') {
            headers['Content-Type'] = 'application/json';
        }
        if (tenant.value.trim() !== '') {
            headers['X-Tenant-ID'] = tenant.value.trim();
        }
        if (token.value.trim() !== '') {
            headers.Authorization = `Bearer ${token.value.trim()}`;
        }

        response.textContent = `Sending ${requestMethod} ${requestUrl} ...`;

        try {
            const result = await fetch(requestUrl, {
                method: requestMethod,
                headers,
                body: ['GET', 'DELETE'].includes(requestMethod) ? undefined : requestBody,
            });
            const text = await result.text();
            let payload = text;
            try {
                payload = JSON.stringify(JSON.parse(text), null, 2);
            } catch (error) {
                payload = text;
            }
            response.textContent = `${result.status} ${result.statusText}\n\n${payload}`;
        } catch (error) {
            response.textContent = error instanceof Error ? error.message : String(error);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSonarApiTester, { once: true });
} else {
    initSonarApiTester();
}
