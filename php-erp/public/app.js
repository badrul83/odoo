(() => {
    'use strict';

    const state = {
        csrf: '', user: null, permissions: new Set(), superuser: false, companies: [], contactCreateCompanies: [], inventoryCompanies: [], productCompanies: [],
        contacts: [], totalContacts: 0, products: [], totalProducts: 0, inventoryOptions: {}, stockMoves: [], totalStockMoves: 0, currentStockMove: null,
        stockLevels: [], inventoryValuation: [], inventoryValuationTotal: 0, inventoryAccounting: null, inventoryClosingReport: null, currentInventoryClosing: null, inventoryCycleCounts: [], inventoryCycleCountTotal: 0, currentAccountingMove: null, inventoryCycleCountOptions: { locations: [], assignees: [] }, moveProducts: [], inventoryView: 'products', productCatalogView: 'catalog', sharedProductCatalog: null, sharedCatalogSearchTimer: null, companyRecords: [], lookup: {}, defaultCompanyId: null, defaultWarehouseId: null,
        crmRecords: [], totalCrmRecords: 0, crmOptions: {}, crmConfiguration: null, crmContactFilter: null, currentCrmLead: null,
        salesRecords: [], totalSalesRecords: 0, salesOptions: {}, currentSalesOrder: null,
        contracts: { records: [], total: 0, options: {}, current: null },
        commissionPlans: [], commissionPlanOptions: {},
        invoices: [], totalInvoices: 0, invoicingOptions: {}, currentInvoice: null, currentPayment: null,
        purchaseRecords: [], totalPurchaseRecords: 0, purchaseStatusCounts: { draft: 0, sent: 0, purchase: 0, cancelled: 0 }, purchaseOptions: {}, currentPurchaseOrder: null, currentVendorBill: null, purchaseView: 'orders',
        page: 'contacts', dashboard: null, currentContact: null, currentCompany: null, currentBranch: null, companySettingsOptions: { currencies: [], timezones: [] }, branchReturnParentId: null,
        bankRemoved: [], uboRemoved: [],
        contactChatter: { contactId: null, companyId: null, messages: [], activities: [], messageTotal: 0, activityTotal: 0 },
        contactMessageType: 'message',
        userRecords: [], currentUserProfile: null, userPermissionCatalog: [], userSessionOffset: 0, userSessionTotal: 0, userSessionLimit: 40,
        approvalApprovers: [], approvalPolicyRecords: [], approvalRecords: [],
        accountingOptions: {}, accountingRecords: [], accountingReport: 'trial_balance', accountingTotal: 0,
        accountingView: 'overview', manualEntryOptions: {}, manualEntries: [], manualEntryTotal: 0, currentManualEntry: null,
        bankStatementOptions: {}, bankStatements: [], bankStatementTotal: 0, bankStatementDetail: null, bankStatementLineTotal: 0, bankStatementCandidates: [], currentStatementLine: null,
        todoOptions: {}, todoRecords: [], todoTotal: 0, todoStageCounts: {}, todoOffset: 0, todoState: 'open', todoCompanyId: null, currentTodo: null, todoFoldedStages: new Set(),
        discussOptions: {}, discussConversations: [], discussCurrent: null, discussMessages: [], discussMembers: [], discussFilter: 'inbox', discussCompanyId: null, discussHasOlder: false,
        appointments: { options: {}, types: [], bookings: [], bookingTotal: 0, bookingOffset: 0, resources: [], slots: [], view: 'bookings', currentType: null, contactFilter: null, bookingFilter: null },
        calendar: { options: {}, events: [], contactFilter: null, view: 'month', anchorDate: '', showWeekends: true },
        manufacturing: { options: {}, view: 'orders', records: [], currentOrder: null, currentBom: null, currentWorkCenter: null },
        notifications: [], notificationTotal: 0, notificationUnread: 0, notificationPollTimer: null,
        loginTwoFactorPending: false,
    };
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const configuredBasePath = document.querySelector('meta[name="erp-base-path"]')?.content || '';
    const appUrl = (path) => path.startsWith('/') && !path.startsWith('//') ? `${configuredBasePath}${path}` : path;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);

    async function api(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        if (options.body !== undefined) {
            if (!(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
            headers.set('X-CSRF-Token', state.csrf);
        } else if (options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', state.csrf);
        }
        const response = await fetch(appUrl(path), { ...options, headers, credentials: 'same-origin' });
        const type = response.headers.get('content-type') || '';
        const payload = type.includes('application/json') ? await response.json() : null;
        if (!response.ok || !payload?.ok) {
            throw new Error(payload?.error || `Request failed (${response.status}).`);
        }
        if (payload.csrf_token) state.csrf = payload.csrf_token;
        return payload;
    }

    async function downloadMasterData(resource, filters = {}) {
        const query = new URLSearchParams({ resource, ...filters });
        const response = await fetch(appUrl('/api/v1/master-data/export?' + query), { credentials: 'same-origin', headers: { Accept: 'text/csv' } });
        if (!response.ok) {
            const payload = (response.headers.get('content-type') || '').includes('application/json') ? await response.json() : null;
            throw new Error(payload?.error || `Export failed (${response.status}).`);
        }
        const blob = await response.blob();
        const filename = (response.headers.get('content-disposition') || '').match(/filename="?([^";]+)"?/i)?.[1] || `${resource}-export.csv`;
        const url = URL.createObjectURL(blob);
        try { const link = document.createElement('a'); link.href = url; link.download = filename; document.body.append(link); link.click(); link.remove(); }
        finally { window.setTimeout(() => URL.revokeObjectURL(url), 1000); }
    }

    async function downloadProductImportTemplate(companyId) {
        const query = new URLSearchParams({ company_id: String(companyId) });
        const response = await fetch(appUrl('/api/v1/products/import-template?' + query), {
            credentials: 'same-origin', headers: { Accept: 'text/csv' },
        });
        if (!response.ok) {
            const payload = (response.headers.get('content-type') || '').includes('application/json') ? await response.json() : null;
            throw new Error(payload?.error || `Template download failed (${response.status}).`);
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        try {
            const link = document.createElement('a'); link.href = url; link.download = 'products-import-template.csv';
            document.body.append(link); link.click(); link.remove();
        } finally { window.setTimeout(() => URL.revokeObjectURL(url), 1000); }
    }

    function can(permission) { return state.superuser || state.permissions.has(permission); }
    function currencyDecimals(currencyCode) {
        if (state.manualEntryOptions.company?.currency_code === currencyCode && Number.isInteger(Number(state.manualEntryOptions.company?.decimal_places))) {
            return Math.max(0, Math.min(4, Number(state.manualEntryOptions.company.decimal_places)));
        }
        if (state.invoicingOptions.currency_code === currencyCode && Number.isInteger(Number(state.invoicingOptions.decimal_places))) {
            return Math.max(0, Math.min(4, Number(state.invoicingOptions.decimal_places)));
        }
        const currency = (state.lookup.currencies || []).find((row) => row.value === currencyCode);
        return Math.max(0, Math.min(4, Number(currency?.decimal_places ?? 2)));
    }
    function formatCurrencyAmount(value, currencyCode) {
        const places = currencyDecimals(currencyCode);
        return `${currencyCode} ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: places, maximumFractionDigits: places })}`;
    }
    function showMessage(element, message = '', isError = false) {
        element.textContent = message;
        element.hidden = !message;
        element.classList.toggle('error', Boolean(message) && isError);
    }
    function setFormError(id, message = '') { showMessage(document.getElementById(id), message, true); }
    function setPageTitle(title, subtitle = '') {
        $('#page-title').textContent = title;
        $('#page-subtitle').textContent = subtitle;
    }
    async function initializeDashboard(companyId = '') {
        if (!can('dashboards.read')) return;
        const select = $('#dashboard-company');
        const selected = String(companyId || select.value || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect(select, state.companies, { placeholder: 'Select a company…', keepValue: selected });
        showMessage($('#dashboard-message'), 'Loading authorized company summaries…');
        try {
            const query = new URLSearchParams();
            if (selected) query.set('company_id', selected);
            const result = await api(`/api/v1/dashboard?${query}`);
            state.dashboard = result.dashboard;
            const company = result.dashboard.company;
            state.companies = (result.dashboard.companies || state.companies).map((item) => ({ id: Number(item.id), name: item.name }));
            if (!state.companies.some((item) => Number(item.id) === Number(company.id))) state.companies.push({ id: Number(company.id), name: company.name });
            fillSelect(select, state.companies, { placeholder: 'Select a company…', keepValue: String(company.id) });
            renderDashboard(result.dashboard);
            showMessage($('#dashboard-message'));
            $('#dashboard-as-of').textContent = `Updated ${new Date(result.dashboard.as_of).toLocaleString()} · one company at a time; amounts retain their document currencies.`;
        } catch (error) {
            showMessage($('#dashboard-message'), error.message, true);
            $('#dashboard-cards').replaceChildren();
            $('#dashboard-quick-actions').replaceChildren();
            $('#dashboard-recent-activity').replaceChildren();
            $('#dashboard-as-of').textContent = '';
            throw error;
        }
    }
    function renderDashboardQuickActions(companyId, companyActions = {}) {
        const root = $('#dashboard-quick-actions'); root.replaceChildren();
        const actions = [
            { key: 'new_contact', label: 'New Contact', available: can('contact_master_data.create'), run: async () => { selectPage('contacts'); await openContact(null, { companyId: String(companyId) }); } },
            { key: 'new_lead', label: 'New Lead', available: can('crm.read') && can('crm.write'), run: async () => { $('#crm-company').value = String(companyId); selectPage('crm'); await openCrmRecord(null, { companyId: String(companyId) }); } },
            { key: 'new_quotation', label: 'New Quotation', available: can('sales.read') && can('sales.write'), run: async () => { $('#sales-company').value = String(companyId); selectPage('sales'); await openSalesOrder(null, { company_id: String(companyId) }); } },
            { key: 'new_rfq', label: 'New RFQ', available: can('purchase.read') && can('purchase.write'), run: async () => { $('#purchase-company').value = String(companyId); selectPage('purchase'); await openPurchaseOrder(null, { company_id: String(companyId) }); } },
            { key: 'new_invoice', label: 'New Invoice', available: can('invoicing.read') && can('invoicing.write') && can('contacts.read'), run: async () => { $('#invoice-company').value = String(companyId); selectPage('invoicing'); await openDirectInvoice({ companyId: String(companyId) }); } },
            { key: 'new_task', label: 'New Task', available: can('todo.read') && can('todo.write'), run: async () => { $('#todo-company').value = String(companyId); state.todoCompanyId = Number(companyId); selectPage('todo'); await openTodoTask(null, { companyId: String(companyId) }); } },
            { key: 'new_contract', label: 'New Contract', available: can('contracts.write'), run: async () => { $('#contracts-company').value = String(companyId); selectPage('contracts'); await openContract(null, { companyId: String(companyId) }); } },
            { key: 'new_appointment', label: 'New Appointment', available: can('appointments.read') && can('appointments.write'), run: async () => { $('#appointments-company').value = String(companyId); state.appointments.view = 'bookings'; selectPage('appointments'); await openAppointmentBooking(); } },
            { key: 'open_inventory', label: 'Open Inventory', available: can('inventory.read'), run: async () => { $('#inventory-company').value = String(companyId); selectPage('inventory'); } },
            { key: 'open_accounts', label: 'Open Accounts', available: can('accounting.read'), run: async () => { $('#accounting-company').value = String(companyId); selectPage('accounting'); } },
            { key: 'company_settings', label: 'Company Settings', available: can('settings.read'), run: async () => selectPage('settings') },
        ];
        const visible = actions.filter((action) => action.available && companyActions[action.key]);
        if (!visible.length) { const empty = document.createElement('p'); empty.className = 'empty-cell'; empty.textContent = 'No quick actions are available for your access rights.'; root.append(empty); return; }
        for (const action of visible) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button dashboard-quick-action'; button.textContent = action.label;
            button.addEventListener('click', async () => {
                button.disabled = true;
                try { await action.run(); }
                catch (error) { showMessage($('#dashboard-message'), error.message, true); }
                finally { button.disabled = false; }
            });
            root.append(button);
        }
    }
    function renderDashboardActivity(records = []) {
        const root = $('#dashboard-recent-activity'); root.replaceChildren();
        if (!records.length) { const empty = document.createElement('p'); empty.className = 'empty-cell'; empty.textContent = 'No recent activity is available for this company and your access rights.'; root.append(empty); return; }
        for (const record of records) {
            const row = document.createElement('div'); row.className = 'dashboard-activity-row';
            const module = document.createElement('span'); module.className = 'dashboard-activity-module'; module.textContent = record.module || 'ERP';
            const event = document.createElement('span'); event.className = 'dashboard-activity-event'; event.textContent = String(record.event_key || 'updated').replace(/[._-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
            const meta = document.createElement('span'); meta.className = 'dashboard-activity-meta';
            // MySQL returns TIMESTAMP values without a timezone suffix here;
            // display them in the browser's local timezone instead of treating
            // an unspecified value as UTC and shifting the shown time.
            const date = new Date(String(record.created_at || '').replace(' ', 'T'));
            const when = Number.isNaN(date.getTime()) ? String(record.created_at || '') : date.toLocaleString();
            meta.textContent = `${record.actor_name || 'System'} · ${when}`;
            row.append(module, event, meta); root.append(row);
        }
    }
    function openDashboardModule(page, companyId) {
        const companySelectors = {
            contacts: '#contact-company', crm: '#crm-company', sales: '#sales-company',
            purchase: '#purchase-company', invoicing: '#invoice-company',
            contracts: '#contracts-company', appointments: '#appointments-company',
            todo: '#todo-company', accounting: '#accounting-company', inventory: '#inventory-company',
        };
        const selector = companySelectors[page];
        if (selector && $(selector)) $(selector).value = String(companyId);
        if (page === 'todo') state.todoCompanyId = Number(companyId);
        if (page === 'appointments') state.appointments.view = 'bookings';
        selectPage(page);
    }
    function renderDashboard(dashboard) {
        const root = $('#dashboard-cards'); root.replaceChildren();
        renderDashboardQuickActions(Number(dashboard.company.id), dashboard.quick_actions || {});
        renderDashboardActivity(dashboard.recent_activity || []);
        if (!dashboard.cards.length) {
            const empty = document.createElement('p'); empty.className = 'empty-cell card'; empty.textContent = 'No module summaries are available for your current access rights.'; root.append(empty); return;
        }
        for (const item of dashboard.cards) {
            const card = document.createElement('button'); card.type = 'button'; card.className = 'dashboard-card card';
            const title = document.createElement('span'); title.className = 'dashboard-card-title'; title.textContent = item.title;
            const value = document.createElement('strong'); value.className = 'dashboard-card-value'; value.textContent = item.value;
            const description = document.createElement('span'); description.className = 'dashboard-card-description'; description.textContent = item.description;
            card.append(title, value, description);
            if (item.details?.length) {
                const details = document.createElement('span'); details.className = 'dashboard-card-details';
                for (const detail of item.details) {
                    const line = document.createElement('span'); line.className = 'dashboard-card-detail';
                    const label = document.createElement('span'); label.textContent = detail.label;
                    const amount = document.createElement('strong'); amount.textContent = detail.value;
                    line.append(label, amount); details.append(line);
                }
                card.append(details);
            }
            card.setAttribute('aria-label', `${item.title}: ${item.value}. Open ${moduleLabel(item.page)}.`);
            card.addEventListener('click', () => openDashboardModule(item.page, dashboard.company.id));
            root.append(card);
        }
    }
    function setHeader() {
        $('#user-menu').hidden = !state.user;
        $('#user-label').textContent = state.user?.name || '';
        const company = [...state.companies, ...state.contactCreateCompanies, ...state.inventoryCompanies, ...state.productCompanies].find((row) => Number(row.id) === Number(state.defaultCompanyId));
        $('#company-label').textContent = company?.name || '';
        $('#notification-button').hidden = !state.user || !can('todo.read');
        const badge = $('#notification-badge');
        badge.hidden = !state.notificationUnread;
        badge.textContent = state.notificationUnread > 99 ? '99+' : String(state.notificationUnread);
    }
    async function loadNotifications(append = false) {
        if (!state.user || !can('todo.read')) return;
        const offset = append ? state.notifications.length : 0;
        const result = await api(`/api/v1/notifications?limit=50&offset=${offset}`);
        state.notifications = append ? [...state.notifications, ...(result.records || [])] : (result.records || []);
        state.notificationTotal = Number(result.total || 0);
        state.notificationUnread = Number(result.unread || 0);
        setHeader();
        renderNotifications();
    }
    function renderNotifications() {
        const list = $('#notification-list');
        list.replaceChildren();
        $('#notification-total').textContent = `${state.notificationTotal} notification${state.notificationTotal === 1 ? '' : 's'} · ${state.notificationUnread} unread`;
        $('#notification-pagination').textContent = state.notificationTotal ? `Showing ${Math.min(state.notifications.length, state.notificationTotal)} of ${state.notificationTotal}` : '';
        $('#notification-load-more').hidden = state.notifications.length >= state.notificationTotal;
        $('#notification-load-more').disabled = false;
        $('#notification-read-all').disabled = !state.notificationUnread;
        if (!state.notifications.length) {
            list.append(Object.assign(document.createElement('p'), { className: 'empty-cell', textContent: 'You have no To-do deadline reminders.' }));
            return;
        }
        for (const item of state.notifications) {
            const row = document.createElement('article'); row.className = `notification-row${item.unread ? ' is-unread' : ''}`;
            const open = document.createElement('button'); open.type = 'button'; open.className = 'notification-open';
            const title = document.createElement('strong'); title.textContent = item.title;
            const body = document.createElement('span'); body.className = 'notification-body'; body.textContent = item.body;
            const meta = document.createElement('span'); meta.className = 'notification-meta';
            const created = new Date(`${String(item.created_at).replace(' ', 'T')}Z`);
            meta.textContent = `${item.deadline ? `Due ${item.deadline} · ` : ''}${Number.isNaN(created.getTime()) ? '' : created.toLocaleString()}`;
            open.append(title, body, meta);
            open.addEventListener('click', async () => {
                try { if (item.unread) await api(`/api/v1/notifications/${item.id}/read`, { method: 'POST', body: JSON.stringify({}) });
                    $('#notification-dialog').close(); await loadNotifications(); selectPage('todo'); await openTodoTask(item.resource_record_id);
                } catch (error) { showMessage($('#notification-message'), error.message, true); }
            });
            row.append(open);
            if (item.unread) {
                const read = document.createElement('button'); read.type = 'button'; read.className = 'button button-small notification-mark-read'; read.textContent = 'Mark read';
                read.addEventListener('click', async () => { read.disabled = true; try { await api(`/api/v1/notifications/${item.id}/read`, { method: 'POST', body: JSON.stringify({}) }); await loadNotifications(); } catch (error) { read.disabled = false; showMessage($('#notification-message'), error.message, true); } });
                row.append(read);
            }
            list.append(row);
        }
    }
    async function openNotificationInbox() {
        if (!state.user || !can('todo.read')) return;
        showMessage($('#notification-message'));
        $('#notification-dialog').showModal();
        try { await loadNotifications(); } catch (error) { showMessage($('#notification-message'), error.message, true); }
    }
    function selectPage(page) {
        if (page === 'contacts' && !can('contacts.read') && !can('contact_master_data.create')) return;
        state.page = page;
        $('#login-page').hidden = true;
        $('#workspace').hidden = false;
        $('#contacts-page').hidden = page !== 'contacts';
        $('#contracts-page').hidden = page !== 'contracts';
        $('#dashboards-page').hidden = page !== 'dashboards';
        $('#discuss-page').hidden = page !== 'discuss';
        $('#appointments-page').hidden = page !== 'appointments';
        $('#calendar-page').hidden = page !== 'calendar';
        $('#todo-page').hidden = page !== 'todo';
        $('#crm-page').hidden = page !== 'crm';
        $('#sales-page').hidden = page !== 'sales';
        $('#purchase-page').hidden = page !== 'purchase';
        $('#invoicing-page').hidden = page !== 'invoicing';
        $('#accounting-page').hidden = page !== 'accounting';
        $('#inventory-page').hidden = page !== 'inventory' && page !== 'products';
        $('#manufacturing-page').hidden = page !== 'manufacturing';
        $('#approvals-page').hidden = page !== 'approvals';
        $('#settings-page').hidden = page !== 'settings';
        $$('.nav-button').forEach((button) => button.classList.toggle('active', button.dataset.page === page));
        if (page === 'dashboards') {
            setPageTitle('Dashboards', 'Company-scoped summaries from the ERP records you are allowed to read.');
            $('#page-actions').replaceChildren();
            initializeDashboard().catch((error) => showMessage($('#dashboard-message'), error.message, true));
        } else if (page === 'contacts') {
            const readContacts = can('contacts.read');
            $('#contacts-toolbar').hidden = !readContacts;
            $('#contacts-list').hidden = !readContacts;
            $('#contact-create-only').hidden = readContacts || !can('contact_master_data.create');
            setPageTitle(readContacts ? 'Contacts' : 'Contact Creation', readContacts
                ? 'Shared company, customer, supplier, and person records.'
                : 'Create company-scoped Contact master records. Existing Contact records stay hidden without Contacts read access.');
            $('#page-actions').replaceChildren();
            if (readContacts) loadContacts().catch((error) => showMessage($('#contacts-message'), error.message, true));
            else if (can('contact_master_data.create')) initializeContactCreation().catch((error) => showMessage($('#contacts-message'), error.message, true));
        } else if (page === 'contracts') {
            setPageTitle('Contracts', 'Company agreements linked to Contacts, Sales quotations, and Purchase RFQs.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Contract';
            add.disabled = !can('contracts.write'); add.addEventListener('click', () => openContract());
            $('#page-actions').replaceChildren(add);
            initializeContracts().catch((error) => showMessage($('#contracts-message'), error.message, true));
        } else if (page === 'discuss') {
            setPageTitle('Discuss', 'Company channels and direct messages across your ERP team.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Conversation';
            add.disabled = !can('discuss.write'); add.addEventListener('click', () => openDiscussDialog());
            $('#page-actions').replaceChildren(add);
            initializeDiscuss().catch((error) => showMessage($('#discuss-message'), error.message, true));
        } else if (page === 'todo') {
            setPageTitle('To-do', 'Assign follow-ups, keep due dates visible, and link tasks to shared business records.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Task';
            add.disabled = !can('todo.write'); add.addEventListener('click', () => openTodoTask());
            const actions = [add];
            if (can('settings.write')) { const stage = document.createElement('button'); stage.className = 'button'; stage.type = 'button'; stage.textContent = 'New Stage'; stage.addEventListener('click', () => openTodoStage()); actions.push(stage); }
            $('#page-actions').replaceChildren(...actions);
            loadTodoWorkspace().catch((error) => showMessage($('#todo-message'), error.message, true));
        } else if (page === 'appointments') {
            setPageTitle('Appointments', 'Configure booking types and resources, manage availability, and link bookings to shared Contacts.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = state.appointments.view === 'types' ? 'New Appointment Type' : state.appointments.view === 'resources' ? 'New Resource' : 'New Booking';
            add.disabled = !can('appointments.write');
            add.addEventListener('click', () => { if (state.appointments.view === 'types') openAppointmentType(); else if (state.appointments.view === 'resources') openAppointmentResource(); else openAppointmentBooking(); });
            $('#page-actions').replaceChildren(add);
            initializeAppointments().catch((error) => showMessage($('#appointments-message'), error.message, true));
        } else if (page === 'calendar') {
            setPageTitle('Calendar', 'Company events and Contact meetings, including synchronized Appointment bookings.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Event';
            add.disabled = !can('calendar.write'); add.addEventListener('click', () => openCalendarEvent().catch((error) => showMessage($('#calendar-message'), error.message, true)));
            $('#page-actions').replaceChildren(add);
            initializeCalendar().catch((error) => showMessage($('#calendar-message'), error.message, true));
        } else if (page === 'crm') {
            setPageTitle('CRM', 'Manage leads, opportunities, customer conversion, ownership, and follow-up activities.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Lead';
            add.disabled = !can('crm.write'); add.addEventListener('click', () => openCrmRecord());
            $('#page-actions').replaceChildren(add);
            initializeCrm().catch((error) => showMessage($('#crm-message'), error.message, true));
        } else if (page === 'sales') {
            setPageTitle('Sales', 'Build quotations from shared customers, products, CRM opportunities, and delivery workflows.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Quotation';
            add.disabled = !can('sales.write'); add.addEventListener('click', () => openSalesOrder());
            const actions = [add];
            if (can('partner_commissions.read')) { const plans = document.createElement('button'); plans.className = 'button'; plans.type = 'button'; plans.textContent = 'Commission Plans'; plans.addEventListener('click', () => openCommissionPlans().catch((error) => showMessage($('#sales-message'), error.message, true))); actions.push(plans); }
            if (can('sales.write')) { const templates = document.createElement('button'); templates.className = 'button'; templates.type = 'button'; templates.textContent = 'Quotation Templates'; templates.addEventListener('click', () => openSalesTemplates().catch((error) => showMessage($('#sales-message'), error.message, true))); actions.push(templates); }
            if (can('accounting.write')) { const tax = document.createElement('button'); tax.className = 'button'; tax.type = 'button'; tax.textContent = 'New Tax'; tax.addEventListener('click', openSalesTax); actions.push(tax); }
            $('#page-actions').replaceChildren(...actions);
            initializeSales().catch((error) => showMessage($('#sales-message'), error.message, true));
        } else if (page === 'purchase') {
            setPageTitle('Purchase', 'RFQs, purchase orders, linked receipts, vendor bills, payments, and reconciliation.');
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New RFQ';
            add.disabled = !can('purchase.write'); add.addEventListener('click', () => openPurchaseOrder());
            const actions = [add];
            if (can('accounting.write')) { const tax = document.createElement('button'); tax.className = 'button'; tax.type = 'button'; tax.textContent = 'New Purchase Tax'; tax.addEventListener('click', openPurchaseTax); actions.push(tax); }
            $('#page-actions').replaceChildren(...actions);
            initializePurchase().catch((error) => showMessage($('#purchase-message'), error.message, true));
        } else if (page === 'inventory') {
            setPageTitle('Inventory', 'Products, stock operations, traceability, and company-specific on-hand quantities.');
            initializeInventory().catch((error) => showMessage($('#inventory-message'), error.message, true));
        } else if (page === 'products') {
            setPageTitle('Products', 'Create product master records for companies where you have Products create access.');
            initializeProductCatalog().catch((error) => showMessage($('#inventory-message'), error.message, true));
        } else if (page === 'manufacturing') {
            setPageTitle('Manufacturing', 'Bills of materials, work centers, ordered operations, component reservations, and finished goods.');
            updateManufacturingActions();
            initializeManufacturing().catch((error) => showMessage($('#manufacturing-message'), error.message, true));
        } else if (page === 'invoicing') {
            setPageTitle('Invoicing', 'Customer invoices, credit notes, payments, and balanced accounting entries.');
            $('#page-actions').replaceChildren();
            initializeInvoicing().catch((error) => showMessage($('#invoicing-message'), error.message, true));
        } else if (page === 'accounting') {
            setPageTitle('Accounting', 'A connected workspace for customer and vendor documents, journal entries, reconciliation, and financial reports.');
            const add = document.createElement('button'); add.id = 'accounting-new-entry-action'; add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Journal Entry';
            add.hidden = state.accountingView !== 'entries';
            add.disabled = !can('accounting.write'); add.addEventListener('click', () => openManualJournalEntry());
            const actions = [add];
            if (can('inventory.read')) {
                const valuation = document.createElement('button'); valuation.className = 'button'; valuation.type = 'button'; valuation.textContent = 'Inventory Valuation Setup';
                valuation.addEventListener('click', openInventoryAccountingSettings); actions.push(valuation);
            }
            $('#page-actions').replaceChildren(...actions);
            initializeAccounting().catch((error) => showMessage($('#accounting-message'), error.message, true));
        } else if (page === 'approvals') {
            setPageTitle('Approvals', 'Review assigned Sales and Purchase approvals in their configured order.');
            const refresh=document.createElement('button');refresh.className='button';refresh.type='button';refresh.textContent='Refresh';refresh.addEventListener('click',()=>loadApprovalInbox().catch((error)=>showMessage($('#approvals-message'),error.message,true)));
            $('#page-actions').replaceChildren(refresh);
            loadApprovalInbox().catch((error)=>showMessage($('#approvals-message'),error.message,true));
        } else {
            setPageTitle('Users & Companies', 'Manage user access profiles, company records, and branches.');
            $('#users-card').hidden = !can('settings.write');
            $('#approval-policy-card').hidden = !can('settings.write');
            $('#document-prefixes-card').hidden = !can('settings.read');
            const add = document.createElement('button');
            add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Company';
            add.disabled = !can('settings.write'); add.addEventListener('click', () => openCompany());
            $('#page-actions').replaceChildren(add);
            const tasks = [loadCompanies()]; if (can('settings.write')) tasks.push(loadUsers());
            Promise.all(tasks).then(()=>{if(can('settings.write'))initializeApprovalSettings().catch((error)=>showMessage($('#approval-policy-message'),error.message,true));}).catch((error) => showMessage($('#settings-message'), error.message, true));
        }
    }
    function showSignedOut() {
        state.user = null; state.permissions.clear();
        state.notifications = []; state.notificationTotal = 0; state.notificationUnread = 0;
        setHeader(); $('#workspace').hidden = true; $('#login-page').hidden = false;
        showMessage($('#system-notice'), 'Sign in to load company and contact data.');
    }
    async function loadSession() {
        const result = await api('/api/v1/auth/session');
        state.csrf = result.csrf_token || '';
        state.user = result.user;
        if (state.user) {
            state.calendar.view = ['day','week','month','year'].includes(state.user.calendar_default_view) ? state.user.calendar_default_view : 'month';
            state.calendar.showWeekends = state.user.calendar_show_weekends !== false;
        }
        state.permissions = new Set(result.permissions || []);
        state.superuser = Boolean(result.superuser);
        state.defaultCompanyId = result.default_company_id ? Number(result.default_company_id) : null;
        state.defaultWarehouseId = result.user?.default_warehouse_id ? Number(result.user.default_warehouse_id) : null;
        state.companies = (result.companies || []).map((id) => ({ id: Number(id), name: `Company ${id}` }));
        if (!state.user) { showSignedOut(); return false; }
        setHeader();
        if (can('contacts.read')) {
            try {
                const options = await api('/api/v1/contact-options');
                state.companies = options.options.companies || [];
                state.lookup = options.options;
                setHeader();
            } catch (error) {
                if (!['You do not have permission to read Contacts.'].includes(error.message)) throw error;
            }
        }
        $('#system-notice').hidden = true;
        const contactsButton = $('.nav-button[data-page="contacts"]');
        contactsButton.hidden = !(can('contacts.read') || can('contact_master_data.create'));
        const contractsButton = $('.nav-button[data-page="contracts"]');
        contractsButton.hidden = !can('contracts.read');
        $('#new-contact').hidden = !can('contact_master_data.create');
        $('#export-contacts').hidden = !(can('contacts.read') && can('data_export.allowed'));
        $('#export-products').hidden = !(can('inventory.read') && can('data_export.allowed'));
        $('#import-products').hidden = !(can('products.create') || can('inventory.write'));
        const settingsButton = $('.nav-button[data-page="settings"]');
        settingsButton.hidden = !can('settings.read');
        const discussButton = $('.nav-button[data-page="discuss"]');
        discussButton.hidden = !can('discuss.read');
        const appointmentsButton = $('.nav-button[data-page="appointments"]');
        appointmentsButton.hidden = !can('appointments.read');
        const calendarButton = $('.nav-button[data-page="calendar"]');
        calendarButton.hidden = !(can('calendar.read') || can('calendar.write'));
        const inventoryButton = $('.nav-button[data-page="inventory"]');
        inventoryButton.hidden = !can('inventory.read');
        const productsButton = $('.nav-button[data-page="products"]');
        productsButton.hidden = !can('products.create');
        const manufacturingButton = $('.nav-button[data-page="manufacturing"]');
        manufacturingButton.hidden = !can('manufacturing.read');
        const crmButton = $('.nav-button[data-page="crm"]');
        crmButton.hidden = !can('crm.read');
        const todoButton = $('.nav-button[data-page="todo"]');
        todoButton.hidden = !can('todo.read');
        const salesButton = $('.nav-button[data-page="sales"]');
        salesButton.hidden = !can('sales.read');
        const invoicingButton = $('.nav-button[data-page="invoicing"]');
        invoicingButton.hidden = !can('invoicing.read');
        const accountingButton = $('.nav-button[data-page="accounting"]');
        accountingButton.hidden = !can('accounting.read');
        const purchaseButton = $('.nav-button[data-page="purchase"]');
        purchaseButton.hidden = !can('purchase.read');
        const approvalsButton=$('.nav-button[data-page="approvals"]');
        approvalsButton.hidden=!(can('approvals.read')||can('approvals.write')||can('sales.approve')||can('purchase.approve'));
        const dashboardsButton=$('.nav-button[data-page="dashboards"]');
        dashboardsButton.hidden=!can('dashboards.read');
        // Keep the application menu aligned to the agreed 12-module DEPO scope.
        // Shared backend capabilities remain available to linked workflows; these
        // standalone apps are not exposed in the main navigation.
        for (const page of ['discuss', 'calendar', 'products', 'manufacturing', 'approvals']) {
            const button = $(`.nav-button[data-page="${page}"]`);
            if (button) button.hidden = true;
        }
        if (!can('contacts.read')) {
            if (can('dashboards.read')) selectPage('dashboards');
            else if (can('crm.read')) selectPage('crm');
            else if (can('contracts.read')) selectPage('contracts');
            else if (can('appointments.read')) selectPage('appointments');
            else if (can('todo.read')) selectPage('todo');
            else if (can('sales.read')) selectPage('sales');
            else if (can('purchase.read')) selectPage('purchase');
            else if (can('invoicing.read')) selectPage('invoicing');
            else if (can('accounting.read')) selectPage('accounting');
            else if (can('inventory.read')) selectPage('inventory');
            else if (can('contact_master_data.create')) selectPage('contacts');
            else if (can('settings.read')) selectPage('settings');
            else throw new Error('This account has no enabled application access.');
        } else selectPage('contacts');
        if (can('todo.read')) {
            try { await loadNotifications(); } catch (error) { console.warn('Notification inbox could not be refreshed.', error); }
            if (!state.notificationPollTimer) state.notificationPollTimer = window.setInterval(() => { if (state.user && can('todo.read')) loadNotifications().catch((error) => console.warn('Notification polling failed.', error)); }, 60000);
        } else {
            state.notifications = []; state.notificationTotal = 0; state.notificationUnread = 0; setHeader();
        }
        return true;
    }

    function fillSelect(select, rows, { valueKey = 'id', label = (row) => row.name, placeholder = 'Select…', keepValue = '' } = {}) {
        const previous = keepValue || select.value;
        select.replaceChildren(new Option(placeholder, ''));
        for (const row of rows || []) select.add(new Option(label(row), String(row[valueKey])));
        if (previous && ![...select.options].some((option) => option.value === String(previous))) {
            select.add(new Option(`Unavailable option (${previous})`, String(previous)));
        }
        select.value = previous || '';
    }
    function contactOptionQuery(companyId) {
        return companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
    }
    async function initializeContactCreation() {
        const options = (await api('/api/v1/contact-create-options')).options;
        state.contactCreateCompanies = options.companies || [];
        fillSelect($('#contact-create-company'), state.contactCreateCompanies, {
            placeholder: options.shared_contact_access ? 'Shared contact' : 'Select company',
            keepValue: options.company_id || '',
        });
        setHeader();
        showMessage($('#contacts-message'));
    }
    async function loadContactOptions(companyId = '', creationOnly = false) {
        const endpoint = creationOnly ? '/api/v1/contact-create-options' : '/api/v1/contact-options';
        const payload = await api(endpoint + contactOptionQuery(companyId));
        state.lookup = payload.options;
        if (creationOnly) {
            state.contactCreateCompanies = state.lookup.companies || [];
            setHeader();
        }
        const form = $('#contact-form');
        const sharedContactAccess = creationOnly ? state.lookup.shared_contact_access : true;
        fillSelect(form.elements.company_id, state.lookup.companies, {
            placeholder: sharedContactAccess ? 'Shared contact' : 'Select company',
            keepValue: state.lookup.company_id || companyId || '',
        });
        form.elements.company_id.required = creationOnly && !sharedContactAccess;
        fillSelect(form.elements.parent_contact_id, state.lookup.contacts, { placeholder: 'No parent contact', label: (row) => `${row.name} · ${row.contact_type}` });
        form.elements.parent_contact_id.disabled = creationOnly && !state.lookup.contacts_read_access;
        fillSelect(form.elements.partner_level_id, state.lookup.partner_levels, { placeholder: 'Select partner level' });
        fillSelect(form.elements.customer_salesperson_user_id, state.lookup.users, { placeholder: 'Select salesperson', label: (row) => row.name });
        fillSelect(form.elements.buyer_user_id, state.lookup.users, { placeholder: 'Select buyer', label: (row) => row.name });
        fillSelect(form.elements.customer_pricelist_id, state.lookup.price_lists, { placeholder: 'Select pricelist', label: (row) => `${row.name} (${row.currency_code})` });
        fillSelect(form.elements.customer_payment_terms_id, state.lookup.payment_terms, { placeholder: 'Select payment terms' });
        fillSelect(form.elements.supplier_payment_terms_id, state.lookup.payment_terms, { placeholder: 'Select payment terms' });
        fillSelect(form.elements.customer_payment_method_id, state.lookup.payment_methods, { placeholder: 'Select payment method' });
        fillSelect(form.elements.supplier_payment_method_id, state.lookup.payment_methods, { placeholder: 'Select payment method' });
        fillSelect(form.elements.delivery_method_id, state.lookup.delivery_methods, { placeholder: 'Select delivery method' });
        fillSelect(form.elements.customer_incoterm_code, state.lookup.incoterms, { placeholder: 'Select incoterm', valueKey: 'value' });
        fillSelect(form.elements.supplier_incoterm_code, state.lookup.incoterms, { placeholder: 'Select incoterm', valueKey: 'value' });
        fillSelect(form.elements.supplier_currency_code, state.lookup.currencies, { placeholder: 'Select currency', valueKey: 'value', label: (row) => `${row.value} · ${row.name}` });
        fillSelect(form.elements.receivable_account_id, state.lookup.accounts, { placeholder: 'Select account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect(form.elements.payable_account_id, state.lookup.accounts, { placeholder: 'Select account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect(form.elements.fiscal_position_id, state.lookup.fiscal_positions, { placeholder: 'Select fiscal position' });
        fillSelect(form.elements.commission_plan_id, state.lookup.commission_plans, { placeholder: 'Select commission plan' });
        $('#contact-commission-plan-field').hidden = state.lookup.commission_read_access !== true;
        $('#contact-commission-plan-new').hidden = !(state.lookup.commission_manage_access === true && state.lookup.commission_assign_access === true);
        form.elements.commission_plan_id.disabled = state.lookup.commission_assign_access !== true;
        const accountingAccess = state.lookup.accounting_access !== false;
        form.elements.receivable_account_id.disabled = !accountingAccess;
        form.elements.payable_account_id.disabled = !accountingAccess;
        const bankWrite = can('contacts.bank.write');
        $('#add-bank-account').hidden = !bankWrite;
        $('#add-bank-account').disabled = !bankWrite;
        $('#add-ubo-owner').hidden = !can('contacts.ubo.write');
        return state.lookup;
    }

    function setControl(form, name, value) {
        const control = form.elements.namedItem(name);
        if (!control) return;
        if (control.type === 'checkbox') control.checked = value === true || value === 1 || value === '1';
        else control.value = value === null || value === undefined ? '' : String(value);
    }
    function getControl(form, name) { return form.elements.namedItem(name); }
    function readValue(form, name) {
        const control = getControl(form, name);
        if (!control) return null;
        if (control.type === 'checkbox') return control.checked;
        const value = control.value.trim();
        if (!value) return null;
        if (control.type === 'number' && ['level_weight', 'latitude', 'longitude'].includes(name)) return value;
        if (control.tagName === 'SELECT' && control.value && control.value !== '' && /^\d+$/.test(control.value)) return Number(control.value);
        return value;
    }
    function clearForm(form) {
        form.reset();
        for (const control of $$('input:not([type="checkbox"]),textarea', form)) control.value = '';
        $('#address-list').replaceChildren(); $('#bank-account-list').replaceChildren(); $('#ubo-owner-list').replaceChildren();
        state.bankRemoved = []; state.uboRemoved = [];
    }
    function switchTabs(dialog, targetId) {
        $$('.tab-button', dialog).forEach((button) => button.classList.toggle('active', button.dataset.tab === targetId));
        $$('.tab-panel', dialog).forEach((panel) => {
            const active = panel.id === targetId;
            panel.classList.toggle('active', active); panel.hidden = !active;
        });
    }

    async function loadContacts() {
        if (!can('contacts.read')) return;
        showMessage($('#contacts-message'));
        const query = new URLSearchParams({
            q: $('#contact-search').value.trim(), role: $('#contact-role').value,
            limit: '100', offset: '0',
        });
        if ($('#contact-company').value) query.set('company_id', $('#contact-company').value);
        const payload = await api('/api/v1/contacts?' + query);
        state.contacts = payload.records || []; state.totalContacts = Number(payload.total || 0);
        fillCompanyFilters(); renderContacts();
    }

    async function initializeCrm() {
        const companySelect = $('#crm-company');
        const current = companySelect.value;
        fillSelect(companySelect, state.companies, { placeholder: 'Select company', keepValue: current });
        const chosen = current || state.defaultCompanyId || (state.companies.length === 1 ? state.companies[0].id : '');
        if (chosen) companySelect.value = String(chosen);
        await loadCrmOptions(companySelect.value);
        await loadCrmRecords();
    }

    async function loadCrmOptions(companyId) {
        const suffix = companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
        const payload = await api('/api/v1/crm/options' + suffix);
        state.crmOptions = payload.options || {};
        $('#crm-config-open').hidden = !can('settings.write');
        state.companies = state.crmOptions.companies || state.companies;
        setHeader();
        const selectedCompany = String(state.crmOptions.company_id || companyId || '');
        for (const selector of ['#crm-company', '#crm-record-company']) {
            const select = $(selector); const previous = selector === '#crm-company' ? selectedCompany : select.value;
            fillSelect(select, state.crmOptions.companies || state.companies, { placeholder: 'Select company', keepValue: previous });
            if (previous) select.value = String(previous);
        }
        const priorFilterStage = $('#crm-stage').value;
        fillSelect($('#crm-stage'), state.crmOptions.stages, { placeholder: 'All stages', label: (row) => row.name, keepValue: priorFilterStage });
        fillSelect($('#crm-record-stage'), state.crmOptions.stages, { placeholder: 'Select stage', label: (row) => `${row.name} (${Number(row.probability)}%)` });
        fillSelect($('#crm-record-team'), state.crmOptions.teams, { placeholder: 'No Sales Team' });
        fillSelect($('#crm-record-salesperson'), state.crmOptions.salespeople, { placeholder: 'Unassigned' });
        fillSelect($('#crm-activity-owner'), state.crmOptions.salespeople, { placeholder: 'Current user' });
        fillSelect($('#crm-record-currency'), state.crmOptions.currencies, { placeholder: 'Select currency', valueKey: 'currency_code', label: (row) => `${row.currency_code} · ${row.name}` });
        fillSelect($('#crm-form').elements.partner_contact_id, state.crmOptions.contacts, { placeholder: 'Create Contact on conversion', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        const toolbarStage = $('#crm-stage');
        const defaultCurrency = state.crmOptions.companies?.find((row) => Number(row.id) === Number(selectedCompany))?.currency_code || 'BDT';
        if ([...$('#crm-record-currency').options].some((option) => option.value === defaultCurrency)) $('#crm-record-currency').value = defaultCurrency;
        return state.crmOptions;
    }

    async function openCrmConfiguration() {
        if (!can('settings.write')) return;
        const companyId = $('#crm-company').value;
        if (!companyId) { showMessage($('#crm-message'), 'Select a company before opening Pipeline Settings.', true); return; }
        try {
            await loadCrmConfiguration(companyId);
            setCrmConfigurationView('stages');
            $('#crm-config-dialog').showModal();
        } catch (error) { showMessage($('#crm-message'), error.message, true); }
    }

    async function loadCrmConfiguration(companyId = $('#crm-company').value) {
        if (!can('settings.write') || !companyId) return;
        const result = await api(`/api/v1/crm/configuration?company_id=${encodeURIComponent(companyId)}`);
        state.crmConfiguration = result.configuration || null;
        $('#crm-config-company').textContent = state.crmConfiguration?.company?.name || '';
        fillSelect($('#crm-team-leader'), state.crmConfiguration?.salespeople || [], { placeholder: 'No Team Leader', label: (row) => `${row.name} · ${row.login}` });
        renderCrmConfiguration();
    }

    function renderCrmConfiguration() {
        const config = state.crmConfiguration;
        const stageBody = $('#crm-config-stage-rows'); stageBody.replaceChildren();
        if (!config?.stages?.length) { const row = stageBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 7; cell.className = 'empty-cell'; cell.textContent = 'No pipeline stages are configured.'; }
        for (const stage of config?.stages || []) {
            const row = stageBody.insertRow(); row.insertCell().textContent = stage.name; row.insertCell().textContent = String(stage.sequence); row.insertCell().textContent = `${Number(stage.probability)}%`; row.insertCell().textContent = stage.is_won ? 'Yes' : 'No'; row.insertCell().textContent = stage.active ? 'Active' : 'Archived'; row.insertCell().textContent = String(stage.linked_lead_count);
            const action = row.insertCell(); const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openCrmStage(stage)); action.append(edit);
        }
        const teamBody = $('#crm-config-team-rows'); teamBody.replaceChildren();
        if (!config?.teams?.length) { const row = teamBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 6; cell.className = 'empty-cell'; cell.textContent = 'No sales teams are configured.'; }
        for (const team of config?.teams || []) {
            const row = teamBody.insertRow(); row.insertCell().textContent = team.name; row.insertCell().textContent = team.leader_name || '—'; row.insertCell().textContent = team.email_alias || '—'; row.insertCell().textContent = team.active ? 'Active' : 'Archived'; row.insertCell().textContent = String(team.linked_lead_count);
            const action = row.insertCell(); const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openCrmTeam(team)); action.append(edit);
        }
    }

    function setCrmConfigurationView(view) {
        const stages = view !== 'teams';
        $('#crm-config-stages-view').hidden = !stages; $('#crm-config-teams-view').hidden = stages;
        $('#crm-config-stages-tab').classList.toggle('active', stages); $('#crm-config-teams-tab').classList.toggle('active', !stages);
        showMessage($('#crm-config-message'));
    }

    function openCrmStage(stage = null) {
        const form = $('#crm-stage-form'); form.reset(); form.dataset.id = stage ? String(stage.id) : '';
        $('#crm-stage-dialog-title').textContent = stage ? 'Edit Pipeline Stage' : 'New Pipeline Stage';
        $('#crm-stage-company-label').value = state.crmConfiguration?.company?.name || '';
        form.elements.name.value = stage?.name || '';
        form.elements.sequence.value = String(stage?.sequence ?? Math.min(65535, Math.max(10, ...((state.crmConfiguration?.stages || []).map((item) => item.sequence + 10)))));
        form.elements.probability.value = String(stage?.probability ?? 0);
        form.elements.is_won.checked = Boolean(stage?.is_won);
        form.elements.active.checked = stage ? Boolean(stage.active) : true;
        setFormError('crm-stage-form-error'); $('#crm-stage-dialog').showModal();
    }

    function openCrmTeam(team = null) {
        const form = $('#crm-team-form'); form.reset(); form.dataset.id = team ? String(team.id) : '';
        $('#crm-team-dialog-title').textContent = team ? 'Edit Sales Team' : 'New Sales Team';
        $('#crm-team-company-label').value = state.crmConfiguration?.company?.name || '';
        fillSelect($('#crm-team-leader'), state.crmConfiguration?.salespeople || [], { placeholder: 'No Team Leader', keepValue: team?.leader_user_id || '', label: (row) => `${row.name} · ${row.login}` });
        form.elements.name.value = team?.name || ''; form.elements.email_alias.value = team?.email_alias || ''; form.elements.active.checked = team ? Boolean(team.active) : true;
        setFormError('crm-team-form-error'); $('#crm-team-dialog').showModal();
    }

    async function saveCrmConfigurationRecord(event) {
        event.preventDefault();
        const form = event.currentTarget; const isStage = form.id === 'crm-stage-form'; const kind = isStage ? 'stage' : 'team';
        if (!can('settings.write') || !form.reportValidity()) return;
        const companyId = Number(state.crmConfiguration?.company?.id || $('#crm-company').value); if (!companyId) return;
        const errorId = isStage ? 'crm-stage-form-error' : 'crm-team-form-error'; setFormError(errorId);
        const button = form.querySelector('button[type="submit"]'); button.disabled = true;
        const payload = isStage
            ? { company_id: companyId, name: form.elements.name.value.trim(), sequence: Number(form.elements.sequence.value), probability: form.elements.probability.value, is_won: form.elements.is_won.checked, active: form.elements.active.checked }
            : { company_id: companyId, name: form.elements.name.value.trim(), leader_user_id: form.elements.leader_user_id.value ? Number(form.elements.leader_user_id.value) : null, email_alias: form.elements.email_alias.value.trim(), active: form.elements.active.checked };
        const id = form.dataset.id; const endpoint = `/api/v1/crm/${kind === 'stage' ? 'stages' : 'teams'}${id ? `/${encodeURIComponent(id)}` : ''}`;
        try {
            await api(endpoint, { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
        } catch (error) {
            setFormError(errorId, error.message);
            button.disabled = false;
            return;
        }
        $(`#${kind === 'stage' ? 'crm-stage-dialog' : 'crm-team-dialog'}`).close();
        try {
            await loadCrmOptions(String(companyId)); await loadCrmRecords(); await loadCrmConfiguration(String(companyId));
            showMessage($('#crm-config-message'), `${isStage ? 'Pipeline stage' : 'Sales team'} saved.`);
        } catch (error) { showMessage($('#crm-config-message'), `Saved, but the CRM view could not refresh: ${error.message}`, true); }
        finally { button.disabled = false; }
    }

    async function loadCrmRecords() {
        showMessage($('#crm-message'));
        const query = new URLSearchParams({
            company_id: $('#crm-company').value || '', q: $('#crm-search').value.trim(),
            type: $('#crm-type').value, state: $('#crm-state').value, limit: '100', offset: '0',
        });
        if ($('#crm-stage').value) query.set('stage_id', $('#crm-stage').value);
        if (state.crmContactFilter) query.set('partner_contact_id', String(state.crmContactFilter));
        const payload = await api('/api/v1/crm/leads?' + query);
        state.crmRecords = payload.records || []; state.totalCrmRecords = Number(payload.total || 0);
        renderCrmRecords();
    }

    function renderCrmRecords() {
        const body = $('#crm-rows'); body.replaceChildren();
        if (!state.crmRecords.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 10; cell.className = 'empty-cell';
            cell.textContent = state.totalCrmRecords ? 'No CRM records match these filters.' : 'No leads or opportunities yet. Create a lead to begin.';
        }
        for (const lead of state.crmRecords) {
            const row = body.insertRow();
            const nameCell = row.insertCell();
            const open = document.createElement('button'); open.type = 'button'; open.className = 'contact-link';
            open.textContent = lead.name; open.addEventListener('click', () => openCrmRecord(Number(lead.id)));
            nameCell.append(open);
            const kind = document.createElement('span'); kind.className = 'role-pill'; kind.textContent = lead.record_type; nameCell.append(document.createElement('br'), kind);
            const contactCell = row.insertCell();
            if (lead.partner_contact_id && can('contacts.read')) {
                const contactButton = document.createElement('button'); contactButton.type = 'button'; contactButton.className = 'text-link';
                contactButton.textContent = lead.partner_name || 'Open Contact'; contactButton.addEventListener('click', () => { selectPage('contacts'); openContact(Number(lead.partner_contact_id)); });
                contactCell.append(contactButton);
            } else contactCell.textContent = lead.partner_name || '—';
            row.insertCell().textContent = lead.stage_name || '—';
            row.insertCell().textContent = `${lead.currency_code} ${Number(lead.expected_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            row.insertCell().textContent = `${Number(lead.probability || 0)}%`;
            row.insertCell().textContent = lead.team_name || '—'; row.insertCell().textContent = lead.salesperson_name || 'Unassigned';
            const status = document.createElement('span'); status.className = `status-pill ${lead.state === 'won' ? 'done' : lead.state === 'lost' ? 'cancelled' : ''}`; status.textContent = lead.state; row.insertCell().append(status);
            const actions = row.insertCell(); const action = document.createElement('button'); action.type = 'button'; action.className = 'button button-small'; action.textContent = 'Open'; action.addEventListener('click', () => openCrmRecord(Number(lead.id))); actions.append(action);
        }
        $('#crm-total').textContent = `${state.totalCrmRecords} record${state.totalCrmRecords === 1 ? '' : 's'}`;
    }

    function crmFormPayload() {
        const form = $('#crm-form');
        const fields = ['company_id', 'partner_contact_id', 'name', 'contact_type', 'email', 'phone', 'mobile', 'website', 'record_type', 'stage_id', 'team_id', 'salesperson_user_id', 'expected_revenue', 'currency_code', 'probability', 'priority', 'expected_close_date', 'source', 'medium', 'campaign', 'description'];
        const payload = {};
        for (const field of fields) {
            const control = form.elements.namedItem(field);
            let value = control.value.trim();
            if (!value) value = null;
            if (['company_id', 'partner_contact_id', 'stage_id', 'team_id', 'salesperson_user_id', 'priority'].includes(field) && value !== null) value = Number(value);
            if (['expected_revenue', 'probability'].includes(field)) value = value === null ? '0' : value;
            payload[field] = value;
        }
        return payload;
    }

    async function openCrmRecord(id = null, { companyId = '', contactId = null } = {}) {
        if (!can('crm.read')) return;
        const dialog = $('#crm-dialog'); const form = $('#crm-form');
        form.reset(); form.dataset.id = ''; state.currentCrmLead = null;
        $('#crm-dialog-title').textContent = id ? 'Loading CRM Record…' : 'New Lead';
        setFormError('crm-form-error'); $('#crm-save-state').textContent = '';
        $('#crm-lost-reason-wrap').hidden = true; $('#crm-lost-reason').value = '';
        try {
            const lead = id ? (await api(`/api/v1/crm/leads/${id}`)).lead : null;
            state.currentCrmLead = lead;
            const chosenCompany = String(lead?.company_id || companyId || $('#crm-company').value || state.defaultCompanyId || '');
            await loadCrmOptions(chosenCompany);
            if (lead) {
                form.dataset.id = String(lead.id); $('#crm-dialog-title').textContent = lead.name;
                for (const field of ['company_id', 'partner_contact_id', 'name', 'contact_type', 'email', 'phone', 'mobile', 'website', 'record_type', 'stage_id', 'team_id', 'salesperson_user_id', 'expected_revenue', 'currency_code', 'probability', 'priority', 'expected_close_date', 'source', 'medium', 'campaign', 'description']) {
                    const control = form.elements.namedItem(field); control.value = lead[field] ?? '';
                }
                $('#crm-lost-reason').value = lead.lost_reason || '';
                $('#crm-lost-reason-wrap').hidden = !(lead.state === 'lost' || (lead.state === 'open' && lead.record_type === 'opportunity'));
                renderCrmActivities(lead.activities || []);
            } else {
                const defaultStage = (state.crmOptions.stages || []).find((stage) => stage.name === 'New') || state.crmOptions.stages?.[0];
                form.elements.company_id.value = chosenCompany;
                form.elements.record_type.value = 'lead'; form.elements.contact_type.value = 'person';
                if (contactId) form.elements.partner_contact_id.value = String(contactId);
                if (defaultStage) { form.elements.stage_id.value = String(defaultStage.id); form.elements.probability.value = String(defaultStage.probability); }
                if (state.user?.id) form.elements.salesperson_user_id.value = String(state.user.id);
                const companyCurrency = state.crmOptions.companies?.find((company) => Number(company.id) === Number(chosenCompany))?.currency_code || 'BDT';
                if ([...form.elements.currency_code.options].some((option) => option.value === companyCurrency)) form.elements.currency_code.value = companyCurrency;
                renderCrmActivities([]);
            }
            setCrmFormEditable(!lead || lead.state === 'open');
            $('#crm-form').elements.record_type.disabled = Boolean(lead) || !can('crm.write');
            $('#crm-form').elements.team_id.disabled = !can('crm.assign') || Boolean(lead && lead.state !== 'open');
            $('#crm-form').elements.salesperson_user_id.disabled = !can('crm.assign') || Boolean(lead && lead.state !== 'open');
            $('#crm-activity-owner').disabled = !can('crm.assign');
            $('#crm-save').hidden = Boolean(lead && lead.state !== 'open');
            $('#crm-convert').hidden = !lead || lead.state !== 'open' || lead.record_type !== 'lead';
            $('#crm-mark-won').hidden = !lead || lead.state !== 'open' || lead.record_type !== 'opportunity';
            $('#crm-mark-lost').hidden = !lead || lead.state !== 'open' || lead.record_type !== 'opportunity';
            $('#crm-new-quotation').hidden = !lead || lead.record_type !== 'opportunity' || !['open','won'].includes(lead.state) || !can('sales.read') || !can('sales.write');
            $('#crm-activity-editor').hidden = !lead || lead.state !== 'open' || !can('crm.write');
            if (id) $('#crm-company').value = chosenCompany;
            dialog.showModal();
        } catch (error) { setFormError('crm-form-error', error.message); dialog.showModal(); }
    }

    function setCrmFormEditable(editable) {
        const form = $('#crm-form');
        $$('input,select,textarea', form).forEach((control) => { control.disabled = !editable; });
        $('#crm-save').disabled = !editable || !can('crm.write');
        $('#crm-convert').disabled = !can('crm.write'); $('#crm-mark-won').disabled = !can('crm.write'); $('#crm-mark-lost').disabled = !can('crm.write');
    }

    function renderCrmActivities(activities) {
        const list = $('#crm-activities'); list.replaceChildren();
        if (!activities.length) { const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'No activities have been scheduled.'; list.append(empty); return; }
        for (const activity of activities) {
            const card = document.createElement('div'); card.className = 'crm-activity-card';
            const body = document.createElement('div');
            const title = document.createElement('strong'); title.textContent = activity.summary; body.append(title);
            const meta = document.createElement('small');
            const due = activity.due_at ? new Date(String(activity.due_at).replace(' ', 'T')).toLocaleString() : 'No due date';
            meta.textContent = `${activity.activity_type} · ${due} · ${activity.assigned_name || 'Unassigned'} · ${activity.state}`; body.append(meta);
            card.append(body);
            if (activity.state === 'planned' && can('crm.write')) {
                const done = document.createElement('button'); done.type = 'button'; done.className = 'button button-small'; done.textContent = 'Mark Done';
                done.addEventListener('click', () => completeCrmActivity(Number(activity.id))); card.append(done);
            }
            list.append(card);
        }
    }

    async function saveCrmRecord(event, { keepOpen = true } = {}) {
        if (event) event.preventDefault();
        if (!can('crm.write')) return;
        const form = $('#crm-form'); const id = form.dataset.id || '';
        setFormError('crm-form-error');
        try {
            const result = await api(id ? `/api/v1/crm/leads/${id}` : '/api/v1/crm/leads', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(crmFormPayload()) });
            form.dataset.id = String(result.lead.id); state.currentCrmLead = result.lead;
            await loadCrmRecords();
            if (keepOpen) await openCrmRecord(Number(result.lead.id));
            else $('#crm-dialog').close();
            showMessage($('#crm-message'), id ? 'CRM record updated.' : 'Lead created.');
            return true;
        } catch (error) { setFormError('crm-form-error', error.message); return false; }
    }

    async function convertCrmRecord() {
        const id = $('#crm-form').dataset.id; if (!id) return;
        try {
            if (!await saveCrmRecord(null, { keepOpen: true })) return;
            const result = await api(`/api/v1/crm/leads/${id}/convert`, { method: 'POST', body: JSON.stringify({ partner_contact_id: $('#crm-form').elements.partner_contact_id.value ? Number($('#crm-form').elements.partner_contact_id.value) : null }) });
            state.currentCrmLead = result.lead; await loadCrmRecords(); await openCrmRecord(Number(id));
            showMessage($('#crm-message'), 'Lead converted to an opportunity and linked to Contacts.');
        } catch (error) { setFormError('crm-form-error', error.message); }
    }

    async function closeCrmOpportunity(stateName) {
        const id = $('#crm-form').dataset.id; if (!id) return;
        try {
            if (!await saveCrmRecord(null, { keepOpen: true })) return;
            const body = stateName === 'lost' ? { lost_reason: $('#crm-lost-reason').value.trim() } : {};
            const result = await api(`/api/v1/crm/leads/${id}/${stateName}`, { method: 'POST', body: JSON.stringify(body) });
            state.currentCrmLead = result.lead; await loadCrmRecords(); await openCrmRecord(Number(id));
            showMessage($('#crm-message'), stateName === 'won' ? 'Opportunity marked Won.' : 'Opportunity marked Lost.');
        } catch (error) { setFormError('crm-form-error', error.message); }
    }

    async function createQuotationFromOpportunity() {
        const lead = state.currentCrmLead; if (!lead || !can('sales.read') || !can('sales.write')) return;
        $('#crm-dialog').close(); selectPage('sales');
        await openSalesOrder(null, { company_id: lead.company_id, partner_contact_id: lead.partner_contact_id, crm_lead_id: lead.id });
    }

    async function initializeContracts(companyId = '') {
        if (!can('contracts.read')) return;
        const selected = String(companyId || $('#contracts-company').value || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect($('#contracts-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        if (!selected) { $('#contract-rows').replaceChildren(); showMessage($('#contracts-message'), 'Choose an allowed company to view Contracts.'); return; }
        const params = new URLSearchParams({ company_id: selected, contract_type: $('#contracts-type').value, status: $('#contracts-status').value, q: $('#contracts-search').value.trim(), limit: '100', offset: '0' });
        try {
            const result = await api(`/api/v1/contracts?${params}`); state.contracts.records = result.records || []; state.contracts.total = Number(result.total || 0);
            const body = $('#contract-rows'); body.replaceChildren();
            for (const item of state.contracts.records) {
                const row = body.insertRow(); const name = row.insertCell(); const open = document.createElement('button'); open.type = 'button'; open.className = 'contact-link'; open.textContent = item.name; open.addEventListener('click', () => openContract(Number(item.id))); name.append(open);
                row.insertCell().textContent = ({ customer: 'Customer', supplier: 'Supplier', depo_partner: 'DEPO Partner' })[item.contract_type] || item.contract_type;
                const contact = row.insertCell(); if (can('contacts.read')) { const link = document.createElement('button'); link.type = 'button'; link.className = 'text-link'; link.textContent = item.partner_name; link.addEventListener('click', () => { selectPage('contacts'); openContact(Number(item.partner_contact_id)); }); contact.append(link); } else contact.textContent = item.partner_name;
                row.insertCell().textContent = `${item.start_date}${item.end_date ? ` – ${item.end_date}` : ' – Open-ended'}`; row.insertCell().textContent = item.warehouse_name || '—';
                const status = document.createElement('span'); status.className = `status-pill ${item.status === 'active' ? 'done' : item.status === 'cancelled' ? 'cancelled' : item.status === 'draft' ? '' : 'confirmed'}`; status.textContent = item.status; row.insertCell().append(status);
                const action = row.insertCell(); if (can('contracts.write')) { const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Open'; edit.addEventListener('click', () => openContract(Number(item.id))); action.append(edit); }
            }
            if (!state.contracts.records.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 7; cell.className = 'empty-cell'; cell.textContent = 'No Contracts match these filters.'; }
            $('#contracts-total').textContent = `${state.contracts.total} contract${state.contracts.total === 1 ? '' : 's'}`; showMessage($('#contracts-message'));
        } catch (error) { showMessage($('#contracts-message'), error.message, true); throw error; }
    }

    async function loadContractOptions(companyId, type = $('#contract-type').value, selectedPartner = '') {
        const params = companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
        const result = await api(`/api/v1/contracts/options${params}`); state.contracts.options = result.options || {};
        const contacts = (state.contracts.options.contacts || []).filter((row) => type === 'customer' ? Number(row.customer) === 1 : type === 'supplier' ? Number(row.supplier) === 1 : true);
        fillSelect($('#contract-company'), state.companies, { placeholder: 'Select company', keepValue: String(state.contracts.options.company_id || companyId || '') });
        fillSelect($('#contract-partner'), contacts, { placeholder: type === 'customer' ? 'Select customer' : type === 'supplier' ? 'Select supplier' : 'Select DEPO partner', keepValue: selectedPartner, label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        fillSelect($('#contract-warehouse'), state.contracts.options.warehouses || [], { placeholder: 'No linked warehouse' });
        return state.contracts.options;
    }

    async function openContract(id = null, prefill = {}) {
        if (id ? !can('contracts.read') : !can('contracts.write')) return;
        const form = $('#contract-form'); form.reset(); form.dataset.id = ''; state.contracts.current = null; setFormError('contract-form-error');
        $('#contract-dialog-title').textContent = id ? 'Loading Contract…' : 'New Contract'; $('#contract-save').hidden = false;
        try {
            const contract = id ? (await api(`/api/v1/contracts/${id}`)).contract : null; state.contracts.current = contract;
            const companyId = String(contract?.company_id || prefill.companyId || $('#contracts-company').value || state.defaultCompanyId || '');
            await loadContractOptions(companyId, contract?.contract_type || prefill.type || 'customer', String(contract?.partner_contact_id || prefill.contactId || ''));
            $('#contract-company').value = companyId; $('#contract-type').value = contract?.contract_type || prefill.type || 'customer';
            if (contract) {
                form.dataset.id = String(contract.id); $('#contract-dialog-title').textContent = `Contract · ${contract.name}`;
                for (const field of ['name','reference','partner_contact_id','warehouse_id','start_date','end_date','terms','state']) if (form.elements[field]) form.elements[field].value = contract[field] ?? '';
            } else {
                const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0,10); form.elements.start_date.value = today; form.elements.state.value = 'draft';
                if (prefill.contactId) form.elements.partner_contact_id.value = String(prefill.contactId);
            }
            const editable = !contract || can('contracts.write'); $$('input,select,textarea', form).forEach((control) => { control.disabled = !editable; }); $('#contract-save').hidden = !editable;
            $('#contract-dialog').showModal();
        } catch (error) { setFormError('contract-form-error', error.message); $('#contract-dialog').showModal(); }
    }

    async function saveContract(event) {
        event.preventDefault(); const form = $('#contract-form'); if (!can('contracts.write') || !form.reportValidity()) return; setFormError('contract-form-error');
        const id = form.dataset.id; const payload = { company_id: Number(form.elements.company_id.value), name: form.elements.name.value.trim(), reference: form.elements.reference.value.trim() || null, contract_type: form.elements.contract_type.value, partner_contact_id: Number(form.elements.partner_contact_id.value), warehouse_id: form.elements.warehouse_id.value ? Number(form.elements.warehouse_id.value) : null, start_date: form.elements.start_date.value, end_date: form.elements.end_date.value || null, terms: form.elements.terms.value, state: form.elements.state.value };
        try { const result = await api(id ? `/api/v1/contracts/${id}` : '/api/v1/contracts', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); $('#contract-dialog').close(); $('#contracts-company').value = String(payload.company_id); await initializeContracts(String(payload.company_id)); showMessage($('#contracts-message'), id ? 'Contract updated.' : 'Contract saved.'); }
        catch (error) { setFormError('contract-form-error', error.message); }
    }

    async function initializeSales() {
        $('#sales-product-catalog').hidden = !can('sales.read');
        const companySelect = $('#sales-company');
        const current = companySelect.value;
        fillSelect(companySelect, state.companies, { placeholder: 'Select company', keepValue: current });
        const chosen = current || state.defaultCompanyId || (state.companies.length === 1 ? state.companies[0].id : '');
        if (chosen) companySelect.value = String(chosen);
        await loadSalesOptions(companySelect.value);
        await loadSalesRecords();
    }

    async function loadSalesOptions(companyId) {
        const suffix = companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
        const payload = await api('/api/v1/sales/options' + suffix);
        state.salesOptions = payload.options || {};
        if (state.salesOptions.companies?.length) { state.companies = state.salesOptions.companies; setHeader(); }
        for (const selector of ['#sales-company', '#sales-record-company']) {
            const select = $(selector); const keep = selector === '#sales-company' ? state.salesOptions.company_id : select.value;
            fillSelect(select, state.salesOptions.companies || state.companies, { placeholder: 'Select company', keepValue: keep });
            if (keep) select.value = String(keep);
        }
        fillSelect($('#sales-customer'), state.salesOptions.contacts, { placeholder: 'Select customer', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        updateSalesContractOptions($('#sales-customer').value, $('#sales-contract').value);
        fillSelect($('#sales-invoice-contact'), state.salesOptions.contacts, { placeholder: 'Customer address', label: (row) => row.name });
        fillSelect($('#sales-delivery-contact'), state.salesOptions.contacts, { placeholder: 'Customer address', label: (row) => row.name });
        fillSelect($('#sales-opportunity'), state.salesOptions.opportunities, { placeholder: 'No linked opportunity', label: (row) => `${row.name}${row.partner_contact_id ? ` · ${state.salesOptions.contacts.find((contact) => Number(contact.id) === Number(row.partner_contact_id))?.name || ''}` : ''}` });
        fillSelect($('#sales-salesperson'), state.salesOptions.salespeople, { placeholder: 'Unassigned' });
        fillSelect($('#sales-pricelist'), state.salesOptions.pricelists, { placeholder: 'Company currency', label: (row) => `${row.name} · ${row.currency_code}` });
        fillSelect($('#sales-template'), state.salesOptions.quotation_templates || [], { placeholder: 'No quotation template' });
        fillSelect($('#sales-payment-terms'), state.salesOptions.payment_terms, { placeholder: 'Immediate Payment' });
        fillSelect($('#sales-warehouse'), state.salesOptions.warehouses, { placeholder: 'Select warehouse for goods' });
        fillSelect($('#sales-tax-account'), (state.salesOptions.accounts || []).filter((row) => String(row.account_type).startsWith('liability')), { placeholder: 'Select Tax Payable account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect($('#sales-currency'), state.salesOptions.currencies, { placeholder: 'Select currency', valueKey: 'currency_code', label: (row) => `${row.currency_code} · ${row.name}` });
        if ([...$('#sales-currency').options].some((option) => option.value === state.salesOptions.currency_code)) $('#sales-currency').value = state.salesOptions.currency_code;
        return state.salesOptions;
    }

    function updateSalesContractOptions(contactId, keepValue = '') {
        const contracts = (state.salesOptions.contracts || []).filter((row) => Number(row.partner_contact_id) === Number(contactId));
        fillSelect($('#sales-contract'), contracts, { placeholder: 'No linked Contract', keepValue, label: (row) => `${row.name}${row.reference ? ` · ${row.reference}` : ''}` });
    }

    async function openSalesTemplates() {
        if (!can('sales.write')) return;
        const companyId = String($('#sales-company').value || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect($('#sales-template-company'), state.companies, { placeholder: 'Select company', keepValue: companyId });
        fillSelect($('#sales-template-form-company'), state.companies, { placeholder: 'Select company', keepValue: companyId });
        if (companyId) await loadSalesOptions(companyId);
        $('#sales-template-dialog').showModal();
        await loadSalesTemplates();
    }

    async function loadSalesTemplates() {
        const companyId = $('#sales-template-company').value;
        if (!companyId) return;
        const result = await api(`/api/v1/sales/templates?company_id=${encodeURIComponent(companyId)}`);
        const body = $('#sales-template-rows'); body.replaceChildren();
        for (const template of result.templates || []) {
            const row = body.insertRow();
            for (const value of [template.name, Number(template.validity_days) ? `${template.validity_days} days` : 'No expiration', template.pricelist_name || 'Default', String(template.lines?.length || 0), Number(template.active) ? 'Active' : 'Archived']) row.insertCell().textContent = value;
            const actions = row.insertCell();
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.disabled = !Number(template.active); edit.addEventListener('click', () => editSalesTemplate(Number(template.id)).catch((error) => showMessage($('#sales-template-message'), error.message, true))); actions.append(edit);
            if (Number(template.active)) { const archive = document.createElement('button'); archive.type = 'button'; archive.className = 'button button-small'; archive.textContent = 'Archive'; archive.addEventListener('click', async () => { if (!window.confirm(`Archive quotation template “${template.name}”? Existing quotations will keep their saved lines.`)) return; try { await api(`/api/v1/sales/templates/${template.id}/archive`, { method: 'POST', body: JSON.stringify({ company_id: Number(companyId) }) }); await loadSalesTemplates(); await loadSalesOptions(companyId); } catch (error) { showMessage($('#sales-template-message'), error.message, true); } }); actions.append(archive); }
        }
        if (!body.children.length) body.innerHTML = '<tr><td colspan="6" class="empty-cell">No quotation templates for this company yet.</td></tr>';
    }

    async function editSalesTemplate(id = null) {
        const form = $('#sales-template-form'); form.reset(); form.dataset.id = id ? String(id) : ''; $('#sales-template-lines').replaceChildren(); setFormError('sales-template-error');
        const companyId = String($('#sales-template-company').value || state.defaultCompanyId || '');
        fillSelect($('#sales-template-form-company'), state.companies, { placeholder: 'Select company', keepValue: companyId });
        await loadSalesOptions(companyId);
        fillSelect($('#sales-template-pricelist'), state.salesOptions.pricelists || [], { placeholder: 'Use customer or company default', label: (row) => `${row.name} · ${row.currency_code}` });
        if (id) {
            const result = await api(`/api/v1/sales/templates/${id}?company_id=${encodeURIComponent(companyId)}`); const template = result.template;
            $('#sales-template-edit-title').textContent = `Edit · ${template.name}`;
            for (const field of ['company_id','name','validity_days','pricelist_id','terms']) form.elements.namedItem(field).value = template[field] ?? '';
            for (const line of template.lines || []) appendSalesTemplateLine(line);
        } else {
            $('#sales-template-edit-title').textContent = 'New Quotation Template'; form.elements.company_id.value = companyId;
            appendSalesTemplateLine();
        }
        form.elements.company_id.disabled=Boolean(id);
        $('#sales-template-edit-dialog').showModal();
    }

    function appendSalesTemplateLine(line = null) {
        const row = $('#sales-template-lines').insertRow(); row.className = 'sales-template-line';
        const productCell=row.insertCell(); const product=document.createElement('select'); product.name='product_id'; product.required=true; product.add(new Option('Select product',''));
        for (const item of state.salesOptions.products || []) product.add(new Option(`${item.name}${item.internal_reference ? ` · ${item.internal_reference}` : ''}`,String(item.id))); productCell.append(product);
        const browse=document.createElement('button');browse.type='button';browse.className='button button-small shared-catalog-line-button';browse.textContent='Browse Catalog';browse.hidden=!can('sales.write');browse.addEventListener('click',async()=>{try{await openSharedProductCatalog({channel:'sales',companyId:$('#sales-template-form-company').value||state.salesOptions.company_id,onSelect:(selected)=>{state.salesOptions.products=state.salesOptions.products||[];if(!state.salesOptions.products.some((item)=>Number(item.id)===Number(selected.id)))state.salesOptions.products.push(selected);if(![...product.options].some((option)=>option.value===String(selected.id)))product.add(new Option(`${selected.name}${selected.internal_reference?` · ${selected.internal_reference}`:''}`,String(selected.id)));product.value=String(selected.id);product.dispatchEvent(new Event('change',{bubbles:true}));}});}catch(error){setFormError('sales-template-error',error.message);}});productCell.append(browse);
        const descriptionCell=row.insertCell(); const description=document.createElement('input'); description.name='description'; description.maxLength=500; descriptionCell.append(description);
        const quantityCell=row.insertCell(); const quantity=document.createElement('input'); quantity.name='quantity'; quantity.type='number'; quantity.required=true; quantity.min='0.000001'; quantity.step='0.000001'; quantity.value=line?.quantity || '1'; quantityCell.append(quantity);
        const uomCell=row.insertCell(); const uom=document.createElement('select'); uom.name='uom_id'; uom.required=true; uom.add(new Option('Select unit','')); uomCell.append(uom);
        const priceCell=row.insertCell(); const price=document.createElement('input'); price.name='unit_price'; price.type='number'; price.min='0'; price.step='0.000001'; price.value=line?.unit_price || '0'; priceCell.append(price);
        const discountCell=row.insertCell(); const discount=document.createElement('input'); discount.name='discount_percent'; discount.type='number'; discount.min='0'; discount.max='100'; discount.step='0.0001'; discount.value=line?.discount_percent || '0'; discountCell.append(discount);
        const taxCell=row.insertCell(); const tax=document.createElement('select'); tax.name='tax_id'; tax.add(new Option('No Tax','')); for (const item of state.salesOptions.taxes || []) tax.add(new Option(`${item.name} · ${Number(item.amount)}%${Number(item.price_included)?' incl.':''}`,String(item.id))); taxCell.append(tax);
        const removeCell=row.insertCell(); const remove=document.createElement('button'); remove.type='button'; remove.className='remove-row'; remove.textContent='Remove'; remove.addEventListener('click',()=>row.remove()); removeCell.append(remove);
        const fillUnits=(selected='')=>{const item=state.salesOptions.products?.find((candidate)=>Number(candidate.id)===Number(product.value));uom.replaceChildren(new Option('Select unit',''));if(!item)return;for(const unit of state.salesOptions.units||[])if(Number(unit.category_id)===Number(item.category_id))uom.add(new Option(`${unit.name} (${unit.abbreviation})`,String(unit.id)));uom.value=String(selected||item.uom_id);};
        product.addEventListener('change',()=>{const item=state.salesOptions.products?.find((candidate)=>Number(candidate.id)===Number(product.value));if(item){description.value=item.name;price.value=item.list_price;fillUnits();}});
        if(line){product.value=String(line.product_id);description.value=line.description||line.product_name||'';fillUnits(line.uom_id);tax.value=line.tax_id?String(line.tax_id):'';}
    }

    async function saveSalesTemplate(event) {
        event.preventDefault(); const form=$('#sales-template-form'); if(!can('sales.write')||!form.reportValidity())return; setFormError('sales-template-error');
        const payload={company_id:Number(form.elements.company_id.value),name:form.elements.name.value.trim(),validity_days:Number(form.elements.validity_days.value),pricelist_id:form.elements.pricelist_id.value?Number(form.elements.pricelist_id.value):null,terms:form.elements.terms.value.trim(),lines:[...$$('.sales-template-line',$('#sales-template-lines'))].map((row)=>({product_id:row.querySelector('[name="product_id"]').value?Number(row.querySelector('[name="product_id"]').value):null,uom_id:row.querySelector('[name="uom_id"]').value?Number(row.querySelector('[name="uom_id"]').value):null,description:row.querySelector('[name="description"]').value.trim(),quantity:row.querySelector('[name="quantity"]').value,unit_price:row.querySelector('[name="unit_price"]').value,discount_percent:row.querySelector('[name="discount_percent"]').value,tax_id:row.querySelector('[name="tax_id"]').value?Number(row.querySelector('[name="tax_id"]').value):null}))};
        const id=form.dataset.id||'';
        try{await api(id?`/api/v1/sales/templates/${id}`:'/api/v1/sales/templates',{method:id?'PATCH':'POST',body:JSON.stringify(payload)});$('#sales-template-edit-dialog').close();$('#sales-template-company').value=String(payload.company_id);await loadSalesOptions(String(payload.company_id));await loadSalesTemplates();showMessage($('#sales-template-message'),id?'Quotation template updated.':'Quotation template created.');}
        catch(error){setFormError('sales-template-error',error.message);}
    }

    async function applySalesTemplate(templateId, confirmReplace = true) {
        if (!templateId) return;
        const selector=$('#sales-template');
        if ($('#sales-lines').children.length && confirmReplace && !window.confirm('Applying this quotation template replaces the current order lines and terms. Continue?')) { selector.value=selector.dataset.previousTemplateId||''; return false; }
        try {
            const companyId=$('#sales-record-company').value; const result=await api(`/api/v1/sales/templates/${templateId}?company_id=${encodeURIComponent(companyId)}`); const template=result.template;
            $('#sales-lines').replaceChildren(); for(const line of template.lines||[])addSalesLine(line);
            $('#sales-note').value=template.terms||'';
            if(template.pricelist_id){$('#sales-pricelist').value=String(template.pricelist_id);const price=state.salesOptions.pricelists?.find((item)=>Number(item.id)===Number(template.pricelist_id));if(price)$('#sales-currency').value=price.currency_code;}
            if(Number(template.validity_days)>0){const base=$('#sales-order-date').value||new Date().toISOString().slice(0,10);const date=new Date(`${base}T00:00:00Z`);date.setUTCDate(date.getUTCDate()+Number(template.validity_days));$('#sales-validity-date').value=date.toISOString().slice(0,10);} else $('#sales-validity-date').value='';
            return true;
        } catch(error) { setFormError('sales-form-error',error.message); return false; }
    }

    async function loadSalesRecords() {
        showMessage($('#sales-message'));
        const companyId = $('#sales-company').value;
        if (!companyId) { state.salesRecords = []; state.totalSalesRecords = 0; renderSalesRecords(); showMessage($('#sales-message'), 'Choose an allowed company to view quotations.'); return; }
        const query = new URLSearchParams({ company_id: companyId, state: $('#sales-state').value, q: $('#sales-search').value.trim(), limit: '100', offset: '0' });
        const result = await api('/api/v1/sales?' + query);
        state.salesRecords = result.records || []; state.totalSalesRecords = Number(result.total || 0); renderSalesRecords();
    }

    function renderSalesRecords() {
        const body = $('#sales-rows'); body.replaceChildren();
        if (!state.salesRecords.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 9; cell.className = 'empty-cell';
            cell.textContent = state.totalSalesRecords ? 'No quotations match these filters.' : 'No quotations yet. Create a quotation to begin.';
        }
        for (const order of state.salesRecords) {
            const row = body.insertRow();
            const numberCell = row.insertCell(); const number = document.createElement('button'); number.type = 'button'; number.className = 'contact-link'; number.textContent = order.name; number.addEventListener('click', () => openSalesOrder(Number(order.id))); numberCell.append(number);
            const customer = row.insertCell();
            if (can('contacts.read')) { const openContactButton = document.createElement('button'); openContactButton.type = 'button'; openContactButton.className = 'text-link'; openContactButton.textContent = order.customer_name; openContactButton.addEventListener('click', () => { selectPage('contacts'); openContact(Number(order.partner_contact_id)); }); customer.append(openContactButton); }
            else customer.textContent = order.customer_name;
            const opportunity = row.insertCell();
            if (order.crm_lead_id && can('crm.read')) { const openCrmButton = document.createElement('button'); openCrmButton.type = 'button'; openCrmButton.className = 'text-link'; openCrmButton.textContent = order.opportunity_name || 'Open opportunity'; openCrmButton.addEventListener('click', () => { selectPage('crm'); openCrmRecord(Number(order.crm_lead_id)); }); opportunity.append(openCrmButton); }
            else opportunity.textContent = order.opportunity_name || '—';
            row.insertCell().textContent = order.order_date || '—'; row.insertCell().textContent = order.salesperson_name || 'Unassigned';
            row.insertCell().textContent = `${order.currency_code} ${Number(order.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            const deliveryCount = Number(order.delivery_move_count || 0); const delivered = Number(order.delivered_move_count || 0); row.insertCell().textContent = deliveryCount ? `${delivered}/${deliveryCount} moves done` : (order.state === 'sale' ? 'No goods delivery' : '—');
            const status = document.createElement('span'); status.className = `status-pill ${order.state === 'sale' ? 'done' : order.state === 'cancelled' ? 'cancelled' : order.state === 'sent' ? 'confirmed' : ''}`; status.textContent = order.state === 'draft' ? 'Quotation' : order.state === 'sent' ? 'Quotation Sent' : order.state === 'sale' ? 'Sales Order' : 'Cancelled'; row.insertCell().append(status);
            const action = row.insertCell(); const open = document.createElement('button'); open.type = 'button'; open.className = 'button button-small'; open.textContent = 'Open'; open.addEventListener('click', () => openSalesOrder(Number(order.id))); action.append(open);
        }
        $('#sales-total').textContent = `${state.totalSalesRecords} quotation${state.totalSalesRecords === 1 ? '' : 's'} / order${state.totalSalesRecords === 1 ? '' : 's'}`;
    }

    async function openCommissionPlans() {
        if (!can('partner_commissions.read')) return;
        await loadCommissionPlans();
        const company = state.companies.find((item) => Number(item.id) === Number($('#sales-company').value));
        $('#commission-plan-company-context').textContent = `Company: ${company?.name || 'Shared records'}`;
        $('#commission-plans-dialog').showModal();
    }

    async function loadCommissionPlans() {
        const companyId = $('#sales-company').value;
        const query = new URLSearchParams(); if (companyId) query.set('company_id', companyId);
        const result = await api('/api/v1/partner-commissions?' + query);
        state.commissionPlans = result.plans || [];
        state.commissionPlanOptions.can_manage = Boolean(result.can_manage);
        renderCommissionPlans();
    }

    function renderCommissionPlans() {
        const body = $('#commission-plan-rows'); body.replaceChildren();
        if (!state.commissionPlans.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 6; cell.className = 'empty-cell'; cell.textContent = 'No commission plans are configured for this company.'; }
        for (const plan of state.commissionPlans) {
            const row = body.insertRow();
            row.insertCell().textContent = plan.name;
            row.insertCell().textContent = plan.company_name || 'Shared';
            row.insertCell().textContent = plan.purchase_default_product_name || '—';
            row.insertCell().textContent = `${(plan.rules || []).length} rule${(plan.rules || []).length === 1 ? '' : 's'}`;
            const actions = row.insertCell();
            if (plan.can_manage && can('partner_commissions.manage')) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openCommissionPlanEditor(plan).catch((error) => setFormError('commission-plan-error', error.message))); actions.append(edit);
                if (Number(plan.active)) { const archive = document.createElement('button'); archive.type = 'button'; archive.className = 'button button-small button-danger'; archive.textContent = 'Archive'; archive.addEventListener('click', async () => { if (!window.confirm(`Archive “${plan.name}”?`)) return; try { await api(`/api/v1/partner-commissions/${plan.id}/archive`, { method: 'POST', body: JSON.stringify({}) }); await loadCommissionPlans(); } catch (error) { showMessage($('#commission-plans-message'), error.message, true); } }); actions.append(archive); }
            } else actions.textContent = 'Read only';
            row.insertCell().textContent = Number(plan.active) ? 'Active' : 'Archived';
        }
        $('#commission-plan-new').hidden = !state.commissionPlanOptions.can_manage;
    }

    async function loadCommissionPlanOptions(companyId) {
        const query = new URLSearchParams(); if (companyId) query.set('company_id', companyId);
        const result = await api('/api/v1/partner-commissions/options?' + query);
        state.commissionPlanOptions = result.options || {};
        const form = $('#commission-plan-form');
        fillSelect(form.elements.company_id, state.commissionPlanOptions.manageable_companies || [], { placeholder: 'Shared Plan', keepValue: companyId || '' });
        fillSelect(form.elements.purchase_default_product_id, state.commissionPlanOptions.products || [], { placeholder: 'No purchase default product', label: (row) => row.internal_reference ? `${row.internal_reference} · ${row.name}` : row.name });
        return state.commissionPlanOptions;
    }

    async function openCommissionPlanEditor(plan = null, context = {}) {
        const form = $('#commission-plan-form'); form.reset(); form.dataset.id = plan ? String(plan.id) : ''; $('#commission-plan-rules').replaceChildren(); setFormError('commission-plan-error');
        form.dataset.returnToContact = context.returnToContact ? '1' : '0';
        form.dataset.contactCompanyId = context.companyId || '';
        form.dataset.contactCreationOnly = context.creationOnly ? '1' : '0';
        $('#commission-plan-editor-title').textContent = plan ? 'Edit Commission Plan' : 'Create Commission Plan';
        const companyId = plan ? (plan.company_id ?? '') : (context.companyId ?? $('#sales-company').value ?? '');
        const options = await loadCommissionPlanOptions(String(companyId || ''));
        if (!options.can_manage) throw new Error('You do not have Commission Plan management access for this company.');
        fillSelect(form.elements.company_id, options.manageable_companies || [], { placeholder: 'Shared Plan', keepValue: plan?.company_id == null ? '' : String(plan.company_id) });
        form.elements.company_id.disabled = Boolean(plan);
        form.elements.name.value = plan?.name || '';
        form.elements.purchase_default_product_id.value = plan?.purchase_default_product_id ? String(plan.purchase_default_product_id) : '';
        form.elements.active.checked = plan ? Boolean(Number(plan.active)) : true;
        for (const rule of plan?.rules || []) appendCommissionRule(rule);
        if (!plan && !$('#commission-plan-rules').children.length) appendCommissionRule();
        $('#commission-plan-edit-dialog').showModal();
    }

    function appendCommissionRule(rule = {}) {
        const body = $('#commission-plan-rules'); const row = body.insertRow();
        const optionSets = [
            ['product_category_id', state.commissionPlanOptions.product_categories || [], 'Select category'],
            ['product_id', state.commissionPlanOptions.products || [], 'Select product'],
            ['sale_order_template_id', state.commissionPlanOptions.sale_order_templates || [], 'Select template'],
            ['pricelist_id', state.commissionPlanOptions.pricelists || [], 'Select pricelist'],
        ];
        for (const [field, items, placeholder] of optionSets) {
            const cell = row.insertCell(); const select = document.createElement('select'); select.dataset.field = field; select.setAttribute('aria-label', field.replaceAll('_', ' '));
            fillSelect(select, items, { placeholder }); select.value = rule[field] ? String(rule[field]) : ''; cell.append(select);
        }
        const rateCell = row.insertCell(); const rate = document.createElement('input'); rate.type = 'number'; rate.min = '0'; rate.max = '99999999.9999'; rate.step = '0.0001'; rate.required = true; rate.value = rule.rate ?? '0'; rate.dataset.field = 'rate'; rate.setAttribute('aria-label', 'Rate'); rateCell.append(rate);
        const cappedCell = row.insertCell(); const cappedWrap = document.createElement('label'); cappedWrap.className = 'commission-checkbox'; const capped = document.createElement('input'); capped.type = 'checkbox'; capped.checked = Boolean(Number(rule.is_capped)); capped.dataset.field = 'is_capped'; capped.setAttribute('aria-label', 'Capped'); const cappedText = document.createElement('span'); cappedText.textContent = 'Capped'; cappedWrap.append(capped, cappedText); cappedCell.append(cappedWrap);
        const maxCell = row.insertCell(); const maximum = document.createElement('input'); maximum.type = 'number'; maximum.min = '0.0001'; maximum.max = '9999999999999999.9999'; maximum.step = '0.0001'; maximum.value = rule.max_commission ?? ''; maximum.dataset.field = 'max_commission'; maximum.setAttribute('aria-label', 'Max Commission'); maximum.required = capped.checked; maximum.disabled = !capped.checked; maxCell.append(maximum);
        capped.addEventListener('change', () => { maximum.disabled = !capped.checked; maximum.required = capped.checked; if (!capped.checked) maximum.value = ''; });
        const removeCell = row.insertCell(); const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-small'; remove.textContent = 'Remove'; remove.setAttribute('aria-label', 'Remove rule'); remove.addEventListener('click', () => row.remove()); removeCell.append(remove);
    }

    async function saveCommissionPlan(event) {
        event.preventDefault(); const form = $('#commission-plan-form'); if (!form.reportValidity()) return;
        setFormError('commission-plan-error');
        const rules = [...$('#commission-plan-rules').rows].map((row) => Object.fromEntries([...row.querySelectorAll('[data-field]')].map((field) => [field.dataset.field, field.type === 'checkbox' ? field.checked : field.value || null])));
        const payload = { company_id: form.elements.company_id.value || null, name: form.elements.name.value.trim(), purchase_default_product_id: form.elements.purchase_default_product_id.value || null, active: form.elements.active.checked, rules };
        try {
            const id = form.dataset.id; const result = await api(id ? `/api/v1/partner-commissions/${id}` : '/api/v1/partner-commissions', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
            const returnToContact = form.dataset.returnToContact === '1'; const contactCompanyId = form.dataset.contactCompanyId || ''; const creationOnly = form.dataset.contactCreationOnly === '1';
            $('#commission-plan-edit-dialog').close();
            if (returnToContact) { await loadContactOptions(contactCompanyId, creationOnly); $('#contact-form').elements.commission_plan_id.value = String(result.plan_id); setContactFormEditable(state.currentContact ? can('contacts.write') : Boolean(state.lookup.contact_create_access)); }
            if ($('#commission-plans-dialog').open) { await loadCommissionPlans(); showMessage($('#commission-plans-message'), id ? 'Commission Plan updated.' : 'Commission Plan created.'); }
        } catch (error) { setFormError('commission-plan-error', error.message); }
    }

    function addSalesLine(line = null) {
        const body = $('#sales-lines'); const row = body.insertRow(); row.className = 'sales-line';
        const productCell = row.insertCell(); const product = document.createElement('select'); product.name = 'product_id'; product.required = true;
        product.add(new Option('Select product', ''));
        for (const item of state.salesOptions.products || []) product.add(new Option(`${item.name}${item.internal_reference ? ` · ${item.internal_reference}` : ''}`, String(item.id)));
        const browse = document.createElement('button'); browse.type = 'button'; browse.className = 'button button-small shared-catalog-line-button'; browse.textContent = 'Browse Catalog'; browse.hidden = !can('sales.write'); browse.addEventListener('click', async () => {
            try { await openSharedProductCatalog({ channel: 'sales', companyId: $('#sales-record-company').value || state.salesOptions.company_id, onSelect: (selected) => { if (!(state.salesOptions.products || []).some((item) => Number(item.id) === Number(selected.id))) state.salesOptions.products.push(selected); if (![...product.options].some((option) => option.value === String(selected.id))) product.add(new Option(`${selected.name}${selected.internal_reference ? ` · ${selected.internal_reference}` : ''}`, String(selected.id))); product.value = String(selected.id); product.dispatchEvent(new Event('change', { bubbles: true })); } }); }
            catch (error) { setFormError('sales-form-error', error.message); }
        });
        productCell.append(product, browse);
        const descriptionCell = row.insertCell(); const description = document.createElement('input'); description.name = 'description'; description.maxLength = 500; description.placeholder = 'Product description'; descriptionCell.append(description);
        const quantityCell = row.insertCell(); const quantity = document.createElement('input'); quantity.name = 'quantity'; quantity.type = 'number'; quantity.required = true; quantity.min = '0.000001'; quantity.step = '0.000001'; quantity.value = line?.quantity || '1'; quantityCell.append(quantity);
        const invoicePolicyCell = row.insertCell(); invoicePolicyCell.className = 'sales-line-policy';
        const uomCell = row.insertCell(); const uom = document.createElement('select'); uom.name = 'uom_id'; uom.required = true; uom.disabled = true; uom.add(new Option('Select unit', '')); uomCell.append(uom);
        const priceCell = row.insertCell(); const price = document.createElement('input'); price.name = 'unit_price'; price.type = 'number'; price.min = '0'; price.step = '0.000001'; price.value = line?.unit_price || '0'; priceCell.append(price);
        const discountCell = row.insertCell(); const discount = document.createElement('input'); discount.name = 'discount_percent'; discount.type = 'number'; discount.min = '0'; discount.max = '100'; discount.step = '0.0001'; discount.value = line?.discount_percent || '0'; discountCell.append(discount);
        const taxCell = row.insertCell(); const tax = document.createElement('select'); tax.name = 'tax_id'; tax.add(new Option('No Tax', ''));
        for (const item of state.salesOptions.taxes || []) tax.add(new Option(`${item.name} · ${Number(item.amount)}%${Number(item.price_included) ? ' incl.' : ''}`, String(item.id)));
        taxCell.append(tax);
        const subtotalCell = row.insertCell(); subtotalCell.className = 'sales-line-subtotal';
        const removeCell = row.insertCell(); const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'remove-row sales-line-remove'; remove.textContent = 'Remove'; remove.addEventListener('click', () => { row.remove(); calculateSalesTotals(); }); removeCell.append(remove);
        if (line) { product.value = String(line.product_id); description.value = line.description || line.product_name || ''; tax.value = line.tax_id ? String(line.tax_id) : ''; }
        let currentUomFactor = 1;
        const fillLineUom = (selectedId = '') => {
            const selectedProduct = state.salesOptions.products?.find((item) => Number(item.id) === Number(product.value));
            uom.replaceChildren(new Option('Select unit', ''));
            if (!selectedProduct) { uom.disabled = true; currentUomFactor = 1; return; }
            for (const unit of state.salesOptions.units || []) if (Number(unit.category_id) === Number(selectedProduct.category_id)) uom.add(new Option(`${unit.name} (${unit.abbreviation})`, String(unit.id)));
            uom.disabled = false; uom.value = String(selectedId || selectedProduct.uom_id);
            currentUomFactor = Number((state.salesOptions.units || []).find((unit) => Number(unit.id) === Number(uom.value))?.factor_to_reference || 1);
        };
        const showInvoicePolicy = () => { const selected = state.salesOptions.products?.find((item) => Number(item.id) === Number(product.value)); invoicePolicyCell.textContent = selected?.invoice_policy === 'delivered' ? 'Delivered quantities' : selected ? 'Ordered quantities' : '—'; };
        fillLineUom(line?.uom_id || '');
        showInvoicePolicy();
        product.addEventListener('change', () => {
            const selected = state.salesOptions.products?.find((item) => Number(item.id) === Number(product.value));
            if (selected) { description.value = selected.name; price.value = selected.list_price; }
            showInvoicePolicy();
            fillLineUom();
            calculateSalesTotals();
        });
        uom.addEventListener('change', () => {
            const nextFactor = Number((state.salesOptions.units || []).find((unit) => Number(unit.id) === Number(uom.value))?.factor_to_reference || currentUomFactor || 1);
            if (currentUomFactor > 0 && nextFactor > 0) price.value = (Number(price.value || 0) * nextFactor / currentUomFactor).toFixed(6);
            currentUomFactor = nextFactor; calculateSalesTotals();
        });
        for (const input of [quantity, price, discount, tax]) input.addEventListener('input', calculateSalesTotals);
        tax.addEventListener('change', calculateSalesTotals);
        calculateSalesTotals();
    }

    function calculateSalesTotals() {
        let untaxed = 0, taxAmount = 0, total = 0;
        for (const row of $$('.sales-line', $('#sales-lines'))) {
            const quantity = Number(row.querySelector('[name="quantity"]').value || 0); const price = Number(row.querySelector('[name="unit_price"]').value || 0);
            const discount = Math.max(0, Math.min(100, Number(row.querySelector('[name="discount_percent"]').value || 0)));
            const selectedTax = state.salesOptions.taxes?.find((item) => Number(item.id) === Number(row.querySelector('[name="tax_id"]').value));
            const gross = quantity * price * (1 - discount / 100); const rate = Number(selectedTax?.amount || 0); const included = Number(selectedTax?.price_included || 0);
            const taxValue = included && rate ? gross - gross / (1 + rate / 100) : gross * rate / 100;
            const subtotal = included ? gross - taxValue : gross; const lineTotal = included ? gross : gross + taxValue;
            untaxed += subtotal; taxAmount += taxValue; total += lineTotal;
            row.querySelector('.sales-line-subtotal').textContent = subtotal.toFixed(2);
        }
        $('#sales-untaxed').textContent = untaxed.toFixed(2); $('#sales-tax').textContent = taxAmount.toFixed(2); $('#sales-total-amount').textContent = total.toFixed(2);
    }

    async function openSalesOrder(id = null, prefill = {}) {
        if (!can('sales.read')) return;
        const dialog = $('#sales-dialog'); const form = $('#sales-form'); form.reset(); form.dataset.id = ''; $('#sales-template').dataset.previousTemplateId=''; $('#sales-lines').replaceChildren(); state.currentSalesOrder = null;
        $('#sales-dialog-title').textContent = id ? 'Loading Quotation…' : 'New Quotation'; $('#sales-deliveries').hidden = true; $('#sales-deliveries').replaceChildren(); setFormError('sales-form-error');
        try {
            const order = id ? (await api(`/api/v1/sales/${id}`)).order : null; state.currentSalesOrder = order;
            const chosenCompany = String(order?.company_id || prefill.company_id || $('#sales-company').value || state.defaultCompanyId || '');
            await loadSalesOptions(chosenCompany);
            if (!order && prefill.catalog_product && !(state.salesOptions.products || []).some((item) => Number(item.id) === Number(prefill.catalog_product.id))) { state.salesOptions.products = state.salesOptions.products || []; state.salesOptions.products.push(prefill.catalog_product); }
            if (order) {
                form.dataset.id = String(order.id); $('#sales-dialog-title').textContent = `${order.name} · ${order.customer_name}`;
                for (const field of ['company_id','partner_contact_id','contract_id','invoice_contact_id','delivery_contact_id','crm_lead_id','sale_order_template_id','salesperson_user_id','pricelist_id','payment_terms_id','warehouse_id','currency_code','order_date','validity_date','client_order_ref','note']) {
                    const control = form.elements.namedItem(field); control.value = order[field] ?? '';
                }
                updateSalesContractOptions(order.partner_contact_id, order.contract_id ? String(order.contract_id) : '');
                $('#sales-template').dataset.previousTemplateId=String(order.sale_order_template_id||'');
                for (const line of order.lines || []) addSalesLine(line);
                renderSalesDeliveries(order.deliveries || []);
            } else {
                form.elements.company_id.value = chosenCompany; form.elements.order_date.value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0,10);
                form.elements.currency_code.value = state.salesOptions.currency_code || '';
                const preferredWarehouse = state.salesOptions.warehouses?.find((warehouse) => Number(warehouse.id) === Number(state.defaultWarehouseId));
                if (state.salesOptions.warehouses?.length) form.elements.warehouse_id.value = String(preferredWarehouse?.id || state.salesOptions.warehouses[0].id);
                if (state.user?.id && (state.salesOptions.salespeople || []).some((person) => Number(person.id) === Number(state.user.id))) form.elements.salesperson_user_id.value = String(state.user.id);
                if (prefill.partner_contact_id) form.elements.partner_contact_id.value = String(prefill.partner_contact_id);
                if (prefill.crm_lead_id) form.elements.crm_lead_id.value = String(prefill.crm_lead_id);
                if (prefill.crm_lead_id) { const opportunity = state.salesOptions.opportunities.find((item) => Number(item.id) === Number(prefill.crm_lead_id)); if (opportunity?.partner_contact_id) form.elements.partner_contact_id.value = String(opportunity.partner_contact_id); }
                const customer = state.salesOptions.contacts.find((item) => Number(item.id) === Number(form.elements.partner_contact_id.value));
                updateSalesContractOptions(form.elements.partner_contact_id.value);
                if (customer) applySalesCustomerDefaults(customer);
                const prefilledProduct = state.salesOptions.products?.find((item) => Number(item.id) === Number(prefill.product_id));
                if (prefilledProduct) addSalesLine({ product_id: prefilledProduct.id, product_name: prefilledProduct.name, description: prefilledProduct.name, quantity: '1', uom_id: prefilledProduct.uom_id, unit_price: prefilledProduct.list_price });
                if (!$('#sales-lines').children.length) addSalesLine();
            }
            const editable = !order || order.state === 'draft';
            $$('input,select,textarea', form).forEach((control) => { control.disabled = !editable; });
            $$('.sales-line-remove', form).forEach((button) => { button.disabled = !editable; });
            $$('.sales-line .shared-catalog-line-button', form).forEach((button) => { button.hidden = !editable || !can('sales.write'); });
            $('#add-sales-line').disabled = !editable || !can('sales.write');
            $('#sales-save').hidden = !editable; $('#sales-save').disabled = !can('sales.write');
            $('#sales-send').hidden = !order || !['draft','sent'].includes(order.state) || !can('sales.write');
            $('#sales-confirm').hidden = !order || !['draft','sent'].includes(order.state) || !can('sales.confirm');
            $('#sales-cancel').hidden = !order || !['draft','sent'].includes(order.state) || !can('sales.confirm');
            $('#sales-invoice').hidden = !order || order.state !== 'sale' || !can('invoicing.read') || !can('invoicing.write');
            if (order && !editable) { $('#sales-save-state').textContent = order.state === 'sale' ? `Confirmed · ${order.confirmed_at || ''}` : `Status · ${order.state}`; }
            if(order?.approval){const approval=order.approval;const step=approval.steps?.find((item)=>item.status==='pending');$('#sales-save-state').textContent=approval.status==='pending'?`Waiting for approval · Layer ${approval.current_sequence} of ${approval.steps.length} (${step?.approver_name||'Assigned approver'})`:approval.status==='approved'?'Approved · Confirm again to create delivery records':approval.status==='rejected'?'Approval rejected · Edit the quotation before requesting again':`Approval ${approval.status}`;}
            dialog.showModal();
        } catch (error) { setFormError('sales-form-error', error.message); dialog.showModal(); }
    }

    function applySalesCustomerDefaults(customer) {
        if (customer.customer_pricelist_id) {
            $('#sales-pricelist').value = String(customer.customer_pricelist_id);
            const pricelist = state.salesOptions.pricelists?.find((item) => Number(item.id) === Number(customer.customer_pricelist_id));
            if (pricelist) $('#sales-currency').value = pricelist.currency_code;
        }
        if (customer.customer_payment_terms_id) $('#sales-payment-terms').value = String(customer.customer_payment_terms_id);
    }

    function renderSalesDeliveries(moves) {
        const panel = $('#sales-deliveries'); panel.replaceChildren();
        if (!moves.length) { panel.hidden = true; return; }
        panel.hidden = false; const heading = document.createElement('strong'); heading.textContent = `Inventory · ${moves.length} delivery move${moves.length === 1 ? '' : 's'}`; panel.append(heading);
        const list = document.createElement('div'); list.className = 'sales-delivery-links';
        for (const move of moves) {
            const item = document.createElement('div'); const label = move.return_of_move_id ? `Customer Return${move.return_of_reference ? ` · from ${move.return_of_reference}` : ''}` : 'Delivery';
            const summary = document.createElement('span'); summary.textContent = `${label} · ${move.reference || 'Stock Move'} · ${move.product_name} · ${move.state} · ${move.done_quantity}/${move.demand_quantity}${move.return_reason ? ` · ${move.return_reason}` : ''}`; item.append(summary);
            if (can('inventory.read')) { const open = document.createElement('button'); open.type = 'button'; open.className = 'button button-small'; open.textContent = move.return_of_move_id ? 'Open Return' : 'Open Delivery'; open.addEventListener('click', async () => { const companyId = state.currentSalesOrder?.company_id; $('#sales-dialog').close(); selectPage('inventory'); if (companyId) $('#inventory-company').value = String(companyId); await initializeInventory(); selectInventoryView('moves'); await openStockMove(Number(move.id)); }); item.append(open); }
            list.append(item);
        }
        panel.append(list);
    }

    function openSalesTax() {
        if (!can('accounting.write')) return;
        const form = $('#sales-tax-form'); form.reset(); setFormError('sales-tax-error');
        fillSelect($('#sales-tax-company'), state.salesOptions.companies || state.companies, { placeholder: 'Select company', keepValue: $('#sales-company').value || state.defaultCompanyId || '' });
        fillSelect($('#sales-tax-account'), (state.salesOptions.accounts || []).filter((row) => String(row.account_type).startsWith('liability')), { placeholder: 'Select Tax Payable account', label: (row) => `${row.code} · ${row.name}` });
        $('#sales-tax-dialog').showModal();
    }

    async function saveSalesTax(event) {
        event.preventDefault(); const form = $('#sales-tax-form'); if (!can('accounting.write') || !form.reportValidity()) return;
        setFormError('sales-tax-error');
        try {
            await api('/api/v1/sales/taxes', { method: 'POST', body: JSON.stringify({
                company_id: Number(form.elements.company_id.value), name: form.elements.name.value.trim(), amount: form.elements.amount.value,
                price_included: form.elements.price_included.checked, account_id: form.elements.account_id.value ? Number(form.elements.account_id.value) : null,
            }) });
            $('#sales-tax-dialog').close(); await loadSalesOptions($('#sales-company').value);
            showMessage($('#sales-message'), 'Sales tax created and available on new quotation lines.');
        } catch (error) { setFormError('sales-tax-error', error.message); }
    }

    async function initializePurchase(companyId = '') {
        if (!can('purchase.read')) return;
        $('#purchase-product-catalog').hidden = false;
        const selected = String(companyId || $('#purchase-company').value || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect($('#purchase-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        fillSelect($('#purchase-form-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        fillSelect($('#purchase-tax-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        $('#purchase-view-bills').hidden = !can('invoicing.read');
        if (selected) await loadPurchaseOptions(selected);
        await loadPurchaseRecords();
    }

    function setPurchaseView(view) {
        state.purchaseView = view === 'bills' && can('invoicing.read') ? 'bills' : 'orders';
        $('#purchase-orders-view').hidden = state.purchaseView !== 'orders';
        $('#purchase-status-summary').hidden = state.purchaseView !== 'orders';
        $('#vendor-bills-view').hidden = state.purchaseView !== 'bills';
        $('#purchase-view-orders').classList.toggle('active', state.purchaseView === 'orders');
        $('#purchase-view-bills').classList.toggle('active', state.purchaseView === 'bills');
        const task = state.purchaseView === 'bills' ? loadVendorBills() : loadPurchaseRecords();
        task.catch((error) => showMessage($('#purchase-message'), error.message, true));
    }

    async function loadPurchaseOptions(companyId) {
        const result = await api('/api/v1/purchase/options?company_id=' + encodeURIComponent(companyId));
        state.purchaseOptions = result.options || {};
        fillSelect($('#purchase-company'), state.companies, { placeholder: 'Select company', keepValue: state.purchaseOptions.company_id });
        fillSelect($('#purchase-form-company'), state.companies, { placeholder: 'Select company', keepValue: state.purchaseOptions.company_id });
        fillSelect($('#purchase-tax-company'), state.companies, { placeholder: 'Select company', keepValue: state.purchaseOptions.company_id });
        fillSelect($('#purchase-vendor'), state.purchaseOptions.suppliers || [], { placeholder: 'Select supplier', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        updatePurchaseContractOptions($('#purchase-vendor').value, $('#purchase-contract').value);
        fillSelect($('#purchase-buyer'), state.purchaseOptions.buyers || [], { placeholder: 'Unassigned buyer' });
        fillSelect($('#purchase-warehouse'), state.purchaseOptions.warehouses || [], { placeholder: 'Select receiving warehouse' });
        fillSelect($('#purchase-terms'), state.purchaseOptions.payment_terms || [], { placeholder: 'Supplier default / immediate payment' });
        fillSelect($('#vendor-payment-journal'), state.purchaseOptions.payment_journals || [], { placeholder: 'Select Bank / Cash Journal', label: (row) => `${row.code} · ${row.name}` });
        const assetAccounts = (state.purchaseOptions.accounts || []).filter((row) => ['asset_current','asset_non_current'].includes(row.account_type));
        fillSelect($('#purchase-tax-account'), assetAccounts, { placeholder: 'No recoverable tax account', label: (row) => `${row.code} · ${row.name}` });
        $('#purchase-currency').value = state.purchaseOptions.currency_code || '';
        return state.purchaseOptions;
    }

    function updatePurchaseContractOptions(contactId, keepValue = '') {
        const contracts = (state.purchaseOptions.contracts || []).filter((row) => Number(row.partner_contact_id) === Number(contactId));
        fillSelect($('#purchase-contract'), contracts, { placeholder: 'No linked Contract', keepValue, label: (row) => `${row.name}${row.reference ? ` · ${row.reference}` : ''}` });
    }

    async function loadPurchaseRecords() {
        showMessage($('#purchase-message'));
        const companyId = $('#purchase-company').value;
        if (!companyId) { state.purchaseRecords=[]; state.totalPurchaseRecords=0; state.purchaseStatusCounts={draft:0,sent:0,purchase:0,cancelled:0}; renderPurchaseRecords(); showMessage($('#purchase-message'),'Choose an allowed company to view RFQs and Purchase Orders.'); return; }
        const query = new URLSearchParams({ company_id: companyId, state: $('#purchase-state').value, q: $('#purchase-search').value.trim(), limit:'100', offset:'0' });
        const result = await api('/api/v1/purchase?' + query);
        state.purchaseRecords=result.records||[]; state.totalPurchaseRecords=Number(result.total||0); state.purchaseStatusCounts=result.status_counts||{draft:0,sent:0,purchase:0,cancelled:0}; renderPurchaseRecords();
    }

    function renderPurchaseStatusSummary() {
        const root=$('#purchase-status-summary');root.replaceChildren();
        const counts=state.purchaseStatusCounts||{};const total=Object.values(counts).reduce((sum,value)=>sum+Number(value||0),0);
        const cards=[
            {state:'draft',label:'Requests for Quotation',count:counts.draft||0},
            {state:'sent',label:'RFQs Sent',count:counts.sent||0},
            {state:'purchase',label:'Purchase Orders',count:counts.purchase||0},
            {state:'all',label:'All Documents',count:total},
        ];
        for(const item of cards){const button=document.createElement('button');button.type='button';button.className=`purchase-status-card${$('#purchase-state').value===item.state?' active':''}`;button.setAttribute('aria-pressed',String($('#purchase-state').value===item.state));const label=document.createElement('span');label.textContent=item.label;const value=document.createElement('strong');value.textContent=Number(item.count).toLocaleString();button.append(label,value);button.addEventListener('click',()=>{if($('#purchase-state').value===item.state)return;$('#purchase-state').value=item.state;loadPurchaseRecords().catch((error)=>showMessage($('#purchase-message'),error.message,true));});root.append(button);}
    }

    function renderPurchaseRecords() {
        const body=$('#purchase-rows'); body.replaceChildren();
        renderPurchaseStatusSummary();
        if (!state.purchaseRecords.length) { const row=body.insertRow(); const cell=row.insertCell(); cell.colSpan=8; cell.className='empty-cell'; cell.textContent=state.totalPurchaseRecords?'No Purchase records match these filters.':'No RFQs yet. Create a Request for Quotation to begin.'; }
        for (const order of state.purchaseRecords) {
            const row=body.insertRow(); const numberCell=row.insertCell(); const number=document.createElement('button'); number.type='button';number.className='contact-link';number.textContent=order.name;number.addEventListener('click',()=>openPurchaseDetail(Number(order.id)));numberCell.append(number);
            const supplier=row.insertCell(); if(can('contacts.read')){const contact=document.createElement('button');contact.type='button';contact.className='text-link';contact.textContent=order.supplier_name;contact.addEventListener('click',()=>{selectPage('contacts');openContact(Number(order.partner_contact_id));});supplier.append(contact);}else supplier.textContent=order.supplier_name;
            row.insertCell().textContent=order.company_name||'—';row.insertCell().textContent=order.buyer_name||'Unassigned';row.insertCell().textContent=order.order_deadline||'—';
            const activities=row.insertCell();const activityCount=Number(order.activities_count||0);if(activityCount&&can('todo.read')){const openActivities=document.createElement('button');openActivities.type='button';openActivities.className='purchase-activity-link';openActivities.textContent=`${activityCount} task${activityCount===1?'':'s'}`;openActivities.setAttribute('aria-label',`Open ${activityCount} activities linked to ${order.name}`);openActivities.addEventListener('click',()=>{$('#todo-company').value=String(order.company_id);$('#todo-state').value='all';$('#todo-search').value=order.name;selectPage('todo');});activities.append(openActivities);}else activities.textContent=activityCount?String(activityCount):'—';
            row.insertCell().textContent=formatCurrencyAmount(order.total_amount,order.currency_code);
            const status=document.createElement('span');status.className=`status-pill ${order.state==='purchase'?'done':order.state==='cancelled'?'cancelled':order.state==='sent'?'confirmed':''}`;status.textContent=order.state==='draft'?'RFQ':order.state==='sent'?'RFQ Sent':order.state==='purchase'?'Purchase Order':'Cancelled';row.insertCell().append(status);
        }
        $('#purchase-total').textContent=`${state.totalPurchaseRecords} RFQ / Purchase Order${state.totalPurchaseRecords===1?'':'s'}`;
    }

    function addPurchaseLine(line = null) {
        const row=document.createElement('div');row.className='purchase-line';
        row.innerHTML='<label>Product<select name="product_id" required><option value="">Select purchasable product</option></select><button type="button" class="button button-small shared-catalog-line-button" data-purchase-line-catalog>Browse Catalog</button></label><label>Description<input name="description" maxlength="500" placeholder="Product description"></label><label>Quantity<input name="quantity" type="number" min="0.000001" step="0.000001" value="1" required></label><label>Unit<select name="uom_id" required><option value="">Select unit</option></select></label><label>Unit Price<input name="unit_price" type="number" min="0" step="0.000001" value="0" required></label><label>Discount %<input name="discount_percent" type="number" min="0" max="100" step="0.0001" value="0"></label><label>Purchase Tax<select name="tax_id"><option value="">No tax</option></select></label><button type="button" class="button button-small purchase-line-remove" aria-label="Remove line">Remove</button>';
        const product=row.querySelector('[name="product_id"]');
        for(const item of state.purchaseOptions.products||[]) product.add(new Option(`${item.name}${item.internal_reference?` · ${item.internal_reference}`:''}`,String(item.id)));
        const tax=row.querySelector('[name="tax_id"]');for(const item of state.purchaseOptions.taxes||[])tax.add(new Option(`${item.name} · ${Number(item.amount)}%`,String(item.id)));
        const unit=row.querySelector('[name="uom_id"]');
        const setUnits=()=>{const selected=(state.purchaseOptions.products||[]).find((item)=>Number(item.id)===Number(product.value));unit.replaceChildren(new Option('Select unit',''));if(!selected)return;const units=(state.purchaseOptions.units||[]).filter((item)=>Number(item.category_id)===Number((state.purchaseOptions.products||[]).find((item)=>Number(item.id)===Number(product.value))?.category_id));for(const item of units)unit.add(new Option(`${item.name} (${item.abbreviation})`,String(item.id)));unit.value=String(selected.purchase_uom_id);const price=row.querySelector('[name="unit_price"]');if(!line)price.value=selected.cost_price||'0';};
        product.addEventListener('change',setUnits);row.querySelector('.purchase-line-remove').addEventListener('click',()=>row.remove());
        row.querySelector('[data-purchase-line-catalog]').addEventListener('click',async()=>{try{await openSharedProductCatalog({channel:'purchase',companyId:$('#purchase-form-company').value||state.purchaseOptions.company_id,onSelect:(selected)=>{if(!(state.purchaseOptions.products||[]).some((item)=>Number(item.id)===Number(selected.id)))state.purchaseOptions.products.push(selected);if(![...product.options].some((option)=>option.value===String(selected.id)))product.add(new Option(`${selected.name}${selected.internal_reference?` · ${selected.internal_reference}`:''}`,String(selected.id)));product.value=String(selected.id);product.dispatchEvent(new Event('change',{bubbles:true}));row.querySelector('[name="description"]').value=selected.name;}});}catch(error){setFormError('purchase-form-error',error.message);}});
        if(line){if(![...product.options].some((option)=>option.value===String(line.product_id)))product.add(new Option(line.product_name||'Unavailable product',String(line.product_id)));product.value=String(line.product_id);setUnits();if(![...unit.options].some((option)=>option.value===String(line.uom_id)))unit.add(new Option(line.uom_name||'Saved unit',String(line.uom_id)));unit.value=String(line.uom_id);row.querySelector('[name="description"]').value=line.description||'';row.querySelector('[name="quantity"]').value=line.quantity||'1';row.querySelector('[name="unit_price"]').value=line.unit_price||'0';row.querySelector('[name="discount_percent"]').value=line.discount_percent||'0';tax.value=line.tax_id?String(line.tax_id):'';}
        $('#purchase-lines').append(row);
    }

    async function openPurchaseOrder(id = null, prefill = {}) {
        if(!can('purchase.write'))return;const form=$('#purchase-form');form.reset();form.dataset.id='';$('#purchase-lines').replaceChildren();$('#purchase-save-state').textContent='';setFormError('purchase-form-error');
        try{const order=id?(await api(`/api/v1/purchase/${id}`)).order:null;const company=String(order?.company_id||prefill.company_id||$('#purchase-company').value||state.defaultCompanyId||'');await loadPurchaseOptions(company);$('#purchase-form-company').value=company;$('#purchase-currency').value=state.purchaseOptions.currency_code||'';
            if(!order&&prefill.catalog_product&&!(state.purchaseOptions.products||[]).some((item)=>Number(item.id)===Number(prefill.catalog_product.id))){state.purchaseOptions.products=state.purchaseOptions.products||[];state.purchaseOptions.products.push(prefill.catalog_product);}
            if(order){form.dataset.id=String(order.id);$('#purchase-dialog-title').textContent=`${order.name} · ${order.supplier_name}`;for(const field of ['partner_contact_id','contract_id','buyer_user_id','warehouse_id','payment_terms_id','currency_code','order_date','order_deadline','expected_arrival','vendor_reference','bill_control_policy','note']){if(form.elements.namedItem(field))form.elements.namedItem(field).value=order[field]??'';}updatePurchaseContractOptions(order.partner_contact_id,order.contract_id?String(order.contract_id):'');form.elements.arrival_confirmation.checked=Boolean(Number(order.arrival_confirmation));form.elements.three_way_match.checked=Boolean(Number(order.three_way_match));for(const line of order.lines||[])addPurchaseLine(line);}
            else{const now=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);form.elements.order_date.value=now;form.elements.currency_code.value=state.purchaseOptions.currency_code||'';if(prefill.partner_contact_id)form.elements.partner_contact_id.value=String(prefill.partner_contact_id);updatePurchaseContractOptions(form.elements.partner_contact_id.value);if(prefill.partner_contact_id){const vendor=(state.purchaseOptions.suppliers||[]).find((item)=>Number(item.id)===Number(prefill.partner_contact_id));if(vendor?.supplier_payment_terms_id)form.elements.payment_terms_id.value=String(vendor.supplier_payment_terms_id);}if(state.user?.id&&(state.purchaseOptions.buyers||[]).some((item)=>Number(item.id)===Number(state.user.id)))form.elements.buyer_user_id.value=String(state.user.id);const preferredWarehouse=state.purchaseOptions.warehouses?.find((warehouse)=>Number(warehouse.id)===Number(state.defaultWarehouseId));if(state.purchaseOptions.warehouses?.length)form.elements.warehouse_id.value=String(preferredWarehouse?.id||state.purchaseOptions.warehouses[0].id);const prefilledProduct=(state.purchaseOptions.products||[]).find((item)=>Number(item.id)===Number(prefill.product_id));if(prefilledProduct)addPurchaseLine({product_id:prefilledProduct.id,product_name:prefilledProduct.name,description:prefilledProduct.name,quantity:'1',uom_id:prefilledProduct.purchase_uom_id,unit_price:prefilledProduct.cost_price});if(!$('#purchase-lines').children.length)addPurchaseLine();}
            const editable=!order||order.state==='draft';$$('input,select,textarea',form).forEach((control)=>{control.disabled=!editable;});$('#purchase-line-add').disabled=!editable;$$('.purchase-line-remove',form).forEach((button)=>button.disabled=!editable);$$('.purchase-line .shared-catalog-line-button',form).forEach((button)=>{button.hidden=!editable||!can('purchase.write');});form.querySelector('button[type="submit"]').hidden=!editable;form.querySelector('button[type="submit"]').disabled=!can('purchase.write');$('#purchase-dialog').showModal();
        }catch(error){setFormError('purchase-form-error',error.message);$('#purchase-dialog').showModal();}
    }

    function purchasePayload(form) {
        const lines=$$('.purchase-line',$('#purchase-lines')).map((row)=>({product_id:Number(row.querySelector('[name="product_id"]').value),uom_id:Number(row.querySelector('[name="uom_id"]').value),description:row.querySelector('[name="description"]').value.trim(),quantity:row.querySelector('[name="quantity"]').value,unit_price:row.querySelector('[name="unit_price"]').value,discount_percent:row.querySelector('[name="discount_percent"]').value,tax_id:row.querySelector('[name="tax_id"]').value?Number(row.querySelector('[name="tax_id"]').value):null}));
        return{company_id:Number(form.elements.company_id.value),partner_contact_id:Number(form.elements.partner_contact_id.value),contract_id:form.elements.contract_id.value?Number(form.elements.contract_id.value):null,buyer_user_id:form.elements.buyer_user_id.value?Number(form.elements.buyer_user_id.value):null,warehouse_id:form.elements.warehouse_id.value?Number(form.elements.warehouse_id.value):null,payment_terms_id:form.elements.payment_terms_id.value?Number(form.elements.payment_terms_id.value):null,currency_code:form.elements.currency_code.value,order_date:form.elements.order_date.value,order_deadline:form.elements.order_deadline.value||null,expected_arrival:form.elements.expected_arrival.value||null,arrival_confirmation:form.elements.arrival_confirmation.checked,vendor_reference:form.elements.vendor_reference.value.trim(),bill_control_policy:form.elements.bill_control_policy.value,three_way_match:form.elements.three_way_match.checked,note:form.elements.note.value,lines};
    }

    async function savePurchaseOrder(event) {
        event.preventDefault();const form=$('#purchase-form');if(!form.reportValidity())return;setFormError('purchase-form-error');$('#purchase-save-state').textContent='Saving…';
        try{const id=form.dataset.id;const result=await api(id?`/api/v1/purchase/${id}`:'/api/v1/purchase',{method:id?'PATCH':'POST',body:JSON.stringify(purchasePayload(form))});state.currentPurchaseOrder=result.order;$('#purchase-dialog').close();$('#purchase-company').value=String(result.order.company_id);await loadPurchaseRecords();showMessage($('#purchase-message'),id?'RFQ updated.':'RFQ saved.');}
        catch(error){setFormError('purchase-form-error',error.message);}finally{$('#purchase-save-state').textContent='';}
    }

    async function openPurchaseDetail(id) {
        if(!can('purchase.read'))return;setFormError('purchase-detail-error');try{const order=(await api(`/api/v1/purchase/${id}`)).order;state.currentPurchaseOrder=order;$('#purchase-detail-title').textContent=`${order.name} · ${order.supplier_name}`;const approvalText=order.approval?.status==='pending'?` · Waiting for approval (layer ${order.approval.current_sequence}/${order.approval.steps.length})`:order.approval?.status==='approved'?' · Approved; confirm again to create receipt records':order.approval?.status==='rejected'?' · Approval rejected':'';$('#purchase-detail-summary').textContent=`${order.state.toUpperCase()}${approvalText} · ${order.order_date} · ${order.currency_code} ${Number(order.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} · Vendor Ref ${order.vendor_reference||'—'} · Buyer ${order.buyer_name||'Unassigned'} · Terms ${order.payment_terms_name||'—'} · Billing ${order.bill_control_policy}${Number(order.three_way_match)?' · Three-way match':''}`;
            const lines=$('#purchase-detail-lines');lines.replaceChildren();for(const line of order.lines||[]){const row=lines.insertRow();row.insertCell().textContent=line.description||line.product_name;row.insertCell().textContent=`${line.quantity} ${line.uom_abbreviation||''}`;row.insertCell().textContent=`${line.received_quantity||'0'} ${line.uom_abbreviation||''}`;row.insertCell().textContent=`${line.billed_quantity||'0'} ${line.uom_abbreviation||''}`;row.insertCell().textContent=formatCurrencyAmount(line.unit_price,order.currency_code);row.insertCell().textContent=`${line.tax_name_snapshot||'—'} ${Number(line.tax_rate_snapshot||0)}%`;row.insertCell().textContent=formatCurrencyAmount(line.total,order.currency_code);}
            const receipts=$('#purchase-detail-receipts');receipts.replaceChildren();if(!order.receipts?.length){const row=receipts.insertRow();const cell=row.insertCell();cell.colSpan=6;cell.className='empty-cell';cell.textContent='Receipts are created when this RFQ is confirmed.';}for(const move of order.receipts||[]){const row=receipts.insertRow();row.insertCell().textContent=move.return_of_move_id?`${move.reference} · Return to supplier from ${move.return_of_reference||'receipt'}`:move.reference;row.insertCell().textContent=move.product_name;row.insertCell().textContent=move.demand_quantity;row.insertCell().textContent=move.done_quantity;row.insertCell().textContent=move.state;const cell=row.insertCell();if(can('inventory.read')){const open=document.createElement('button');open.type='button';open.className='button button-small';open.textContent=move.return_of_move_id?'Open Return':'Open Receipt';open.addEventListener('click',()=>{const moveId=Number(move.id);$('#purchase-detail-dialog').close();selectPage('inventory');openStockMove(moveId);});cell.append(open);}else cell.textContent='—';}
            const bills=$('#purchase-detail-bills');bills.replaceChildren();if(!order.bills?.length){const row=bills.insertRow();const cell=row.insertCell();cell.colSpan=8;cell.className='empty-cell';cell.textContent='No vendor bill created yet.';}for(const bill of order.bills||[]){const row=bills.insertRow();const name=row.insertCell();const openName=document.createElement('button');openName.type='button';openName.className='contact-link';openName.textContent=bill.name;openName.addEventListener('click',()=>openVendorBill(Number(bill.id)));name.append(openName);row.insertCell().textContent=bill.bill_date;row.insertCell().textContent=bill.due_date;row.insertCell().textContent=formatCurrencyAmount(bill.total_amount,order.currency_code);row.insertCell().textContent=formatCurrencyAmount(bill.amount_residual,order.currency_code);row.insertCell().textContent=bill.state;row.insertCell().textContent=bill.payment_state;const cell=row.insertCell();const open=document.createElement('button');open.type='button';open.className='button button-small';open.textContent='Open Bill';open.addEventListener('click',()=>openVendorBill(Number(bill.id)));cell.append(open);}
            const active=['draft','sent'].includes(order.state);$('#purchase-edit').hidden=!active||!can('purchase.write');$('#purchase-send').hidden=!active||!can('purchase.write');$('#purchase-confirm').hidden=!active||!can('purchase.confirm')||!can('inventory.write');$('#purchase-create-bill').hidden=order.state!=='purchase'||!can('invoicing.write');$('#purchase-detail-dialog').showModal();
        }catch(error){setFormError('purchase-detail-error',error.message);$('#purchase-detail-dialog').showModal();}
    }

    async function sendPurchaseOrder() {const order=state.currentPurchaseOrder;if(!order)return;try{await api(`/api/v1/purchase/${order.id}/send`,{method:'POST',body:JSON.stringify({})});await loadPurchaseRecords();await openPurchaseDetail(Number(order.id));}catch(error){setFormError('purchase-detail-error',error.message);}}
    async function confirmPurchaseOrder() {const order=state.currentPurchaseOrder;if(!order)return;try{const result=await api(`/api/v1/purchase/${order.id}/confirm`,{method:'POST',body:JSON.stringify({})});await loadPurchaseRecords();await openPurchaseDetail(Number(order.id));showMessage($('#purchase-message'),result.order.approval?.status==='pending'?'Approval requested. The purchase order remains unconfirmed, so no Inventory receipt was created.':'Purchase Order confirmed; linked Inventory receipt moves are ready.');}catch(error){setFormError('purchase-detail-error',error.message);}}
    async function createVendorBill() {const order=state.currentPurchaseOrder;if(!order)return;try{const result=await api(`/api/v1/purchase/${order.id}/bills`,{method:'POST',body:JSON.stringify({})});await loadPurchaseRecords();await openPurchaseDetail(Number(order.id));await openVendorBill(Number(result.bill.id));}catch(error){setFormError('purchase-detail-error',error.message);}}

    async function loadVendorBills() {
        if(!can('invoicing.read'))return;const company=$('#purchase-company').value;if(!company)return;const query=new URLSearchParams({company_id:company,state:'all',q:$('#purchase-search').value.trim(),limit:'100',offset:'0'});const result=await api('/api/v1/purchase/bills?'+query);const body=$('#vendor-bill-rows');body.replaceChildren();for(const bill of result.records||[]){const row=body.insertRow();const cell=row.insertCell();const openName=document.createElement('button');openName.type='button';openName.className='contact-link';openName.textContent=bill.name;openName.addEventListener('click',()=>openVendorBill(Number(bill.id)));cell.append(openName);const supplier=row.insertCell();if(can('contacts.read')){const c=document.createElement('button');c.type='button';c.className='text-link';c.textContent=bill.supplier_name;c.addEventListener('click',()=>{selectPage('contacts');openContact(Number(bill.partner_contact_id));});supplier.append(c);}else supplier.textContent=bill.supplier_name;row.insertCell().textContent=bill.purchase_order_name;row.insertCell().textContent=bill.bill_date;row.insertCell().textContent=bill.due_date;row.insertCell().textContent=formatCurrencyAmount(bill.total_amount,bill.currency_code||state.purchaseOptions.currency_code);row.insertCell().textContent=formatCurrencyAmount(bill.amount_credited,bill.currency_code||state.purchaseOptions.currency_code);row.insertCell().textContent=formatCurrencyAmount(bill.amount_residual,bill.currency_code||state.purchaseOptions.currency_code);row.insertCell().textContent=bill.state;row.insertCell().textContent=bill.payment_state;const action=row.insertCell();const open=document.createElement('button');open.type='button';open.className='button button-small';open.textContent='Open';open.addEventListener('click',()=>openVendorBill(Number(bill.id)));action.append(open);}if(!(result.records||[]).length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=11;cell.className='empty-cell';cell.textContent='No vendor bills match the current search.';}$('#vendor-bill-total').textContent=`${result.total||0} vendor bill${Number(result.total)===1?'':'s'}`;
    }

    async function openVendorBill(id) {
        if(!can('invoicing.read'))return;setFormError('vendor-bill-error');try{const bill=(await api(`/api/v1/purchase/bills/${id}`)).bill;state.currentVendorBill=bill;$('#vendor-bill-title').textContent=`${bill.name} · ${bill.supplier_name}`;$('#vendor-bill-summary').textContent=`${bill.state.toUpperCase()} · ${bill.payment_state} · PO ${bill.purchase_order_name} · Vendor ref ${bill.vendor_reference||'—'} · Bill ${bill.bill_date} · Due ${bill.due_date} · Total ${formatCurrencyAmount(bill.total_amount,bill.currency_code)} · Paid ${formatCurrencyAmount(bill.amount_paid,bill.currency_code)} · Credit Applied ${formatCurrencyAmount(bill.amount_credited,bill.currency_code)} · Residual ${formatCurrencyAmount(bill.amount_residual,bill.currency_code)}`;const billEntry=$('#vendor-bill-accounting-entry');billEntry.replaceChildren();const billEntryLabel=document.createElement('strong');billEntryLabel.textContent='Accounting Entry: ';billEntry.append(billEntryLabel,accountingMoveLink(bill.accounting_move_id,bill.accounting_move_name,bill.accounting_move_state));
            const body=$('#vendor-bill-lines');body.replaceChildren();for(const line of bill.lines||[]){const row=body.insertRow();row.insertCell().textContent=line.description||line.product_name;row.insertCell().textContent=`${line.quantity} ${line.uom_name||''}`;row.insertCell().textContent=formatCurrencyAmount(line.unit_price,bill.currency_code);row.insertCell().textContent=`${line.tax_name_snapshot||'—'} ${Number(line.tax_rate_snapshot||0)}%`;row.insertCell().textContent=formatCurrencyAmount(line.total,bill.currency_code);}
            const journal=$('#vendor-bill-journal-lines');journal.replaceChildren();for(const line of bill.journal_items||[]){const row=journal.insertRow();row.insertCell().textContent=`${line.account_code} · ${line.account_name}`;row.insertCell().textContent=line.label;row.insertCell().textContent=formatCurrencyAmount(line.debit,bill.currency_code);row.insertCell().textContent=formatCurrencyAmount(line.credit,bill.currency_code);}if(!bill.journal_items?.length){const row=journal.insertRow();const cell=row.insertCell();cell.colSpan=4;cell.className='empty-cell';cell.textContent=bill.state==='draft'?'Posting creates balanced journal items.':'Accounting access is required to view journal items.';}
            const payments=$('#vendor-bill-payments');payments.replaceChildren();if(!bill.payments?.length){const row=payments.insertRow();const cell=row.insertCell();cell.colSpan=8;cell.className='empty-cell';cell.textContent='No payments registered.';}for(const payment of bill.payments||[]){const row=payments.insertRow();row.insertCell().textContent=payment.name;row.insertCell().textContent=payment.payment_date;row.insertCell().textContent=formatCurrencyAmount(payment.amount,bill.currency_code);row.insertCell().textContent=payment.state;row.insertCell().textContent=payment.bank_reference||'Not reconciled';row.insertCell().append(accountingMoveLink(payment.accounting_move_id,payment.accounting_move_name,payment.accounting_move_state));row.insertCell().append(accountingMoveLink(payment.bank_move_id,payment.bank_move_name,payment.bank_move_state));const cell=row.insertCell();if(payment.state==='in_payment'&&can('accounting.reconcile')&&can('accounting.post')){const match=document.createElement('button');match.type='button';match.className='button button-small';match.textContent='Match Bank';match.addEventListener('click',()=>openVendorBankMatch(Number(payment.id),payment));cell.append(match);}}
            const creditBody=$('#vendor-credit-note-rows');creditBody.replaceChildren();const credits=bill.credit_notes||[];if(!credits.length){const row=creditBody.insertRow();const cell=row.insertCell();cell.colSpan=9;cell.className='empty-cell';cell.textContent='No supplier credit notes.';}for(const note of credits){const row=creditBody.insertRow();row.insertCell().textContent=note.name;row.insertCell().textContent=note.credit_date;row.insertCell().textContent=`${note.reason} · ${note.state}`;row.insertCell().textContent=formatCurrencyAmount(note.amount,bill.currency_code);row.insertCell().textContent=formatCurrencyAmount(note.amount_refunded,bill.currency_code);row.insertCell().textContent=formatCurrencyAmount(note.amount_applied,bill.currency_code);row.insertCell().textContent=formatCurrencyAmount(note.amount_residual,bill.currency_code);row.insertCell().append(accountingMoveLink(note.accounting_move_id,note.accounting_move_name,note.accounting_move_state));const actions=row.insertCell();if(note.state==='draft'){if(can('invoicing.post')&&can('accounting.post')){const post=document.createElement('button');post.type='button';post.className='button button-small';post.textContent='Post';post.addEventListener('click',()=>postVendorCreditNote(Number(note.id)));actions.append(post);}if(can('invoicing.write')){const cancel=document.createElement('button');cancel.type='button';cancel.className='button button-small';cancel.textContent='Discard';cancel.addEventListener('click',()=>cancelVendorCreditNote(Number(note.id)));actions.append(cancel);}}if(note.state==='posted'&&Number(note.amount_residual)>0&&can('invoicing.write')&&can('accounting.reconcile')&&can('accounting.post')){const apply=document.createElement('button');apply.type='button';apply.className='button button-small';apply.textContent='Apply to Bill';apply.addEventListener('click',()=>openVendorCreditApplication(note));actions.append(apply);}if(note.state==='posted'&&Number(note.amount_residual)>0&&can('invoicing.write')&&can('accounting.post')){const refund=document.createElement('button');refund.type='button';refund.className='button button-small';refund.textContent='Register Refund';refund.addEventListener('click',()=>openVendorCreditRefund(note));actions.append(refund);}}
            const refundBody=$('#vendor-credit-refund-rows');refundBody.replaceChildren();const refunds=credits.flatMap(note=>(note.refunds||[]).map(refund=>({...refund,credit_note_name:note.name})));if(!refunds.length){const row=refundBody.insertRow();const cell=row.insertCell();cell.colSpan=9;cell.className='empty-cell';cell.textContent='No supplier refunds registered.';}for(const refund of refunds){const row=refundBody.insertRow();row.insertCell().textContent=refund.name;row.insertCell().textContent=refund.refund_date;row.insertCell().textContent=refund.credit_note_name;row.insertCell().textContent=formatCurrencyAmount(refund.amount,bill.currency_code);row.insertCell().textContent=refund.state;row.insertCell().textContent=refund.bank_reference||'Not reconciled';row.insertCell().append(accountingMoveLink(refund.accounting_move_id,refund.accounting_move_name,refund.accounting_move_state));row.insertCell().append(accountingMoveLink(refund.bank_move_id,refund.bank_move_name,refund.bank_move_state));const actions=row.insertCell();if(refund.state==='in_payment'&&can('accounting.reconcile')&&can('accounting.post')){const match=document.createElement('button');match.type='button';match.className='button button-small';match.textContent='Match Bank';match.addEventListener('click',()=>openVendorCreditRefundMatch(refund));actions.append(match);}}
            renderVendorCreditApplications(bill);$('#vendor-credit-note-create').hidden=bill.state!=='posted'||credits.some(note=>note.state==='draft'||note.state==='posted')||!can('invoicing.write');$('#vendor-bill-post').hidden=bill.state!=='draft'||!can('invoicing.post')||!can('accounting.post');$('#vendor-bill-pay').hidden=bill.state!=='posted'||Number(bill.amount_residual)<=0||!can('invoicing.write')||!can('accounting.post');$('#vendor-bill-dialog').showModal();
        }catch(error){setFormError('vendor-bill-error',error.message);$('#vendor-bill-dialog').showModal();}
    }

    async function postVendorBill() {const bill=state.currentVendorBill;if(!bill)return;try{await api(`/api/v1/purchase/bills/${bill.id}/post`,{method:'POST',body:JSON.stringify({})});await openVendorBill(Number(bill.id));await loadVendorBills();}catch(error){setFormError('vendor-bill-error',error.message);}}
    function openVendorCreditNote() {const bill=state.currentVendorBill;if(!bill)return;const form=$('#vendor-credit-note-form');form.reset();setFormError('vendor-credit-note-error');$('#vendor-credit-note-bill-info').textContent=`${bill.name} · ${bill.supplier_name} · Full bill amount ${formatCurrencyAmount(bill.total_amount,bill.currency_code)}`;form.elements.credit_date.value=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);$('#vendor-credit-note-dialog').showModal();}
    async function openVendorCreditApplication(note) {
        const source=state.currentVendorBill;if(!source||note.state!=='posted'||Number(note.amount_residual)<=0||!can('invoicing.write')||!can('accounting.reconcile')||!can('accounting.post'))return;
        const form=$('#vendor-credit-apply-form');form.reset();$('#vendor-credit-apply-search').value='';form.dataset.creditNoteId=String(note.id);setFormError('vendor-credit-apply-error');state.vendorCreditApplication={note,bills:[],searchSequence:0};
        $('#vendor-credit-apply-info').textContent=`${note.name} - ${source.supplier_name} - Open credit ${formatCurrencyAmount(note.amount_residual,source.currency_code)}`;fillSelect($('#vendor-credit-apply-bill'),[],{placeholder:'Search for a posted Vendor Bill'});$('#vendor-credit-apply-dialog').showModal();await searchVendorCreditBills();
    }
    async function searchVendorCreditBills() {
        const data=state.vendorCreditApplication;if(!data)return;const source=state.currentVendorBill;if(!source)return;const sequence=++data.searchSequence;const query=new URLSearchParams({company_id:String(source.company_id),partner_contact_id:String(source.partner_contact_id),state:'posted',q:$('#vendor-credit-apply-search').value.trim(),limit:'100',offset:'0'});setFormError('vendor-credit-apply-error');
        try{const result=await api('/api/v1/purchase/bills?'+query);if(state.vendorCreditApplication!==data||data.searchSequence!==sequence)return;const bills=(result.records||[]).filter((bill)=>Number(bill.amount_residual)>0&&bill.currency_code===source.currency_code);const select=$('#vendor-credit-apply-bill');select.value='';data.bills=bills;fillSelect(select,bills,{placeholder:'Select posted Vendor Bill',label:(bill)=>`${bill.name} - Open ${formatCurrencyAmount(bill.amount_residual,bill.currency_code)}`});updateVendorCreditApplicationAmount();if(!bills.length)setFormError('vendor-credit-apply-error','No matching same-supplier posted vendor bills have an open balance in this currency. Search by bill number or reference.');}catch(error){if(state.vendorCreditApplication===data&&data.searchSequence===sequence)setFormError('vendor-credit-apply-error',error.message);}
    }
    function updateVendorCreditApplicationAmount() {const form=$('#vendor-credit-apply-form');const data=state.vendorCreditApplication;if(!data)return;const bill=data.bills.find((row)=>Number(row.id)===Number(form.elements.vendor_bill_id.value));if(!bill){form.elements.amount.value='';form.elements.amount.removeAttribute('max');return;}const places=currencyDecimals(bill.currency_code);const max=Math.min(Number(data.note.amount_residual),Number(bill.amount_residual));form.elements.amount.min=(1/(10**places)).toFixed(places);form.elements.amount.max=String(max);form.elements.amount.step=places?`0.${'0'.repeat(places-1)}1`:'1';form.elements.amount.value=max.toFixed(places);}
    async function applyVendorCredit(event) {event.preventDefault();const form=$('#vendor-credit-apply-form');const data=state.vendorCreditApplication;if(!data||!form.reportValidity())return;setFormError('vendor-credit-apply-error');try{await api(`/api/v1/purchase/credit-notes/${form.dataset.creditNoteId}/applications`,{method:'POST',body:JSON.stringify({vendor_bill_id:Number(form.elements.vendor_bill_id.value),amount:form.elements.amount.value})});$('#vendor-credit-apply-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-credit-apply-error',error.message);}}
    function renderVendorCreditApplications(bill) {const body=$('#vendor-credit-application-rows');body.replaceChildren();const applications=bill.credit_applications||[];if(!applications.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=5;cell.className='empty-cell';cell.textContent='No supplier credits applied to or from this bill.';return;}for(const application of applications){const row=body.insertRow();row.insertCell().textContent=application.credit_note_name;for(const [id,label] of [[application.source_bill_id,application.source_bill_name],[application.target_bill_id,application.target_bill_name]]){const cell=row.insertCell();const link=document.createElement('button');link.type='button';link.className='contact-link';link.textContent=label;link.addEventListener('click',()=>{$('#vendor-bill-dialog').close();openVendorBill(Number(id));});cell.append(link);}row.insertCell().textContent=formatCurrencyAmount(application.amount,bill.currency_code);row.insertCell().textContent=application.applied_at;}}
    async function saveVendorCreditNote(event) {event.preventDefault();const form=$('#vendor-credit-note-form');if(!form.reportValidity()||!state.currentVendorBill)return;setFormError('vendor-credit-note-error');try{await api(`/api/v1/purchase/bills/${state.currentVendorBill.id}/credit-notes`,{method:'POST',body:JSON.stringify({credit_date:form.elements.credit_date.value,reason:form.elements.reason.value.trim()})});$('#vendor-credit-note-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-credit-note-error',error.message);}}
    async function postVendorCreditNote(id) {if(!state.currentVendorBill)return;try{await api(`/api/v1/purchase/credit-notes/${id}/post`,{method:'POST',body:JSON.stringify({})});await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-bill-error',error.message);}}
    async function cancelVendorCreditNote(id) {if(!state.currentVendorBill)return;try{await api(`/api/v1/purchase/credit-notes/${id}/cancel`,{method:'POST',body:JSON.stringify({})});await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-bill-error',error.message);}}
    function openVendorPayment() {const bill=state.currentVendorBill;if(!bill)return;const journals=(state.purchaseOptions.payment_journals||[]).filter((journal)=>Number(journal.outstanding_payments_account_id)>0);if(!journals.length){setFormError('vendor-bill-error','Configure an active Bank / Cash journal with Outstanding Payments before registering a vendor payment.');return;}const form=$('#vendor-payment-form');form.reset();setFormError('vendor-payment-error');fillSelect($('#vendor-payment-journal'),journals,{placeholder:'Select Bank / Cash Journal',label:(row)=>`${row.code} · ${row.name}`});$('#vendor-payment-bill-info').textContent=`${bill.name} · ${bill.supplier_name} · Residual ${formatCurrencyAmount(bill.amount_residual,bill.currency_code)}`;form.elements.payment_date.value=accountingToday();form.elements.amount.value=String(bill.amount_residual);$('#vendor-payment-dialog').showModal();}
    async function saveVendorPayment(event) {event.preventDefault();const form=$('#vendor-payment-form');if(!form.reportValidity()||!state.currentVendorBill)return;setFormError('vendor-payment-error');try{await api(`/api/v1/purchase/bills/${state.currentVendorBill.id}/payments`,{method:'POST',body:JSON.stringify({journal_id:Number(form.elements.journal_id.value),payment_date:form.elements.payment_date.value,amount:form.elements.amount.value,reference:form.elements.reference.value.trim()})});$('#vendor-payment-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-payment-error',error.message);}}
    function openVendorBankMatch(paymentId,payment) {const form=$('#vendor-bank-match-form');form.reset();setFormError('vendor-bank-match-error');form.dataset.paymentId=String(paymentId);$('#vendor-bank-match-info').textContent=`${payment.name} · ${state.currentVendorBill.supplier_name} · ${formatCurrencyAmount(payment.amount,state.currentVendorBill.currency_code)}`;form.elements.transaction_date.value=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);form.elements.amount.value=String(payment.amount);$('#vendor-bank-match-dialog').showModal();}
    async function reconcileVendorPayment(event) {event.preventDefault();const form=$('#vendor-bank-match-form');if(!form.reportValidity())return;setFormError('vendor-bank-match-error');try{await api(`/api/v1/purchase/payments/${form.dataset.paymentId}/reconcile`,{method:'POST',body:JSON.stringify({reference:form.elements.reference.value.trim(),transaction_date:form.elements.transaction_date.value,amount:form.elements.amount.value})});$('#vendor-bank-match-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-bank-match-error',error.message);}}
    function openVendorCreditRefund(note) {const bill=state.currentVendorBill;if(!bill||note.state!=='posted'||Number(note.amount_residual)<=0||!can('invoicing.write')||!can('accounting.post'))return;const form=$('#vendor-credit-refund-form');form.reset();form.dataset.creditNoteId=String(note.id);setFormError('vendor-credit-refund-error');const journals=(state.purchaseOptions.payment_journals||[]).filter((journal)=>Number(journal.outstanding_receipts_account_id)>0);if(!journals.length){setFormError('vendor-credit-refund-error','Configure an active Bank / Cash journal with Outstanding Receipts before registering this refund.');$('#vendor-credit-refund-dialog').showModal();return;}fillSelect($('#vendor-credit-refund-journal'),journals,{placeholder:'Select Bank / Cash Journal',label:(row)=>`${row.code} · ${row.name}`});$('#vendor-credit-refund-info').textContent=`${note.name} · ${bill.supplier_name} · Open credit ${formatCurrencyAmount(note.amount_residual,bill.currency_code)}`;form.elements.refund_date.value=accountingToday();form.elements.amount.max=String(note.amount_residual);form.elements.amount.step=`0.${'0'.repeat(Math.max(0,currencyDecimals(bill.currency_code)-1))}1`;form.elements.amount.value=Number(note.amount_residual).toFixed(currencyDecimals(bill.currency_code));$('#vendor-credit-refund-dialog').showModal();}
    async function registerVendorCreditRefund(event) {event.preventDefault();const form=$('#vendor-credit-refund-form');if(!form.reportValidity()||!state.currentVendorBill)return;const noteId=Number(form.dataset.creditNoteId);if(!noteId)return;setFormError('vendor-credit-refund-error');try{await api(`/api/v1/purchase/credit-notes/${noteId}/refunds`,{method:'POST',body:JSON.stringify({journal_id:Number(form.elements.journal_id.value),refund_date:form.elements.refund_date.value,amount:form.elements.amount.value,reference:form.elements.reference.value.trim()})});$('#vendor-credit-refund-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-credit-refund-error',error.message);}}
    function openVendorCreditRefundMatch(refund) {if(!state.currentVendorBill||refund.state!=='in_payment'||!can('accounting.reconcile')||!can('accounting.post'))return;const form=$('#vendor-credit-refund-match-form');form.reset();form.dataset.refundId=String(refund.id);$('#vendor-credit-refund-match-info').textContent=`${refund.name} · ${refund.credit_note_name} · ${formatCurrencyAmount(refund.amount,state.currentVendorBill.currency_code)}`;form.elements.transaction_date.value=accountingToday();form.elements.reference.value=refund.reference||refund.name;form.elements.amount.value=Number(refund.amount).toFixed(currencyDecimals(state.currentVendorBill.currency_code));setFormError('vendor-credit-refund-match-error');$('#vendor-credit-refund-match-dialog').showModal();}
    async function reconcileVendorCreditRefund(event) {event.preventDefault();const form=$('#vendor-credit-refund-match-form');if(!form.reportValidity()||!form.dataset.refundId)return;setFormError('vendor-credit-refund-match-error');try{await api(`/api/v1/purchase/refunds/${form.dataset.refundId}/reconcile`,{method:'POST',body:JSON.stringify({reference:form.elements.reference.value.trim(),transaction_date:form.elements.transaction_date.value,amount:form.elements.amount.value})});$('#vendor-credit-refund-match-dialog').close();await openVendorBill(Number(state.currentVendorBill.id));await loadVendorBills();}catch(error){setFormError('vendor-credit-refund-match-error',error.message);}}
    async function openPurchaseTax() {if(!can('accounting.write'))return;setFormError('purchase-tax-error');const company=$('#purchase-company').value||state.defaultCompanyId||'';await loadPurchaseOptions(String(company));$('#purchase-tax-form').reset();$('#purchase-tax-company').value=String(company);fillSelect($('#purchase-tax-account'),(state.purchaseOptions.accounts||[]).filter((row)=>['asset_current','asset_non_current'].includes(row.account_type)),{placeholder:'Select Recoverable Tax Account',label:(row)=>`${row.code} · ${row.name}`});$('#purchase-tax-dialog').showModal();}
    async function savePurchaseTax(event) {event.preventDefault();const form=$('#purchase-tax-form');if(!form.reportValidity())return;setFormError('purchase-tax-error');try{await api('/api/v1/purchase/taxes',{method:'POST',body:JSON.stringify({company_id:Number(form.elements.company_id.value),name:form.elements.name.value.trim(),amount:form.elements.amount.value,price_included:form.elements.price_included.checked,account_id:form.elements.account_id.value?Number(form.elements.account_id.value):null})});$('#purchase-tax-dialog').close();await loadPurchaseOptions($('#purchase-company').value);showMessage($('#purchase-message'),'Purchase tax saved for this company.');}catch(error){setFormError('purchase-tax-error',error.message);}}

    async function initializeInvoicing(companyId = '') {
        await initializeAccountingSetup(companyId);
        if (!can('invoicing.read')) return;
        await loadInvoices();
    }

    async function initializeAccountingSetup(companyId = '') {
        if (!can('invoicing.read')) return;
        $('#invoice-new').hidden = !(can('invoicing.write') && can('contacts.read'));
        $('#invoice-product-catalog').hidden = !can('invoicing.read');
        const selected = String(companyId || $('#invoice-company').value || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect($('#invoice-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        fillSelect($('#account-setup-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        fillSelect($('#journal-setup-company'), state.companies, { placeholder: 'Select company', keepValue: selected });
        if (selected) {
            const payload = await api('/api/v1/invoicing/options?company_id=' + encodeURIComponent(selected));
            state.invoicingOptions = payload.options || {};
            for (const selector of ['#invoice-company','#account-setup-company','#journal-setup-company']) $(selector).value = selected;
        } else state.invoicingOptions = {};
        const accounts = state.invoicingOptions.accounts || [];
        const assetAccounts = accounts.filter((row) => ['asset_cash','asset_current'].includes(row.account_type));
        fillSelect($('#journal-default-account'), assetAccounts, { placeholder: 'Select Bank / Cash Account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect($('#journal-outstanding-account'), assetAccounts, { placeholder: 'Select Outstanding Receipts Account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect($('#journal-outstanding-payments-account'), assetAccounts, { placeholder: 'Not configured', label: (row) => `${row.code} · ${row.name}` });
        fillSelect($('#payment-journal'), state.invoicingOptions.payment_journals || [], { placeholder: 'Select Bank / Cash Journal', label: (row) => `${row.code} · ${row.name}` });
        syncJournalSetupFields();
        const editable = can('accounting.write');
        for (const form of [$('#account-setup-form'),$('#journal-setup-form')]) {
            $$('input,select,button', form).forEach((control) => { control.disabled = !editable; });
        }
        renderAccountingSetup();
    }

    function renderAccountingSetup() {
        const options = state.invoicingOptions || {};
        const accounts = options.accounts || []; const journals = options.journals || [];
        const summary = $('#accounting-setup-summary');
        const paymentJournals = options.payment_journals || [];
        summary.textContent = `${accounts.length} active account${accounts.length === 1 ? '' : 's'} · ${journals.length} Sales Journal${journals.length === 1 ? '' : 's'} · ${paymentJournals.length} Bank / Cash Journal${paymentJournals.length === 1 ? '' : 's'} · Currency ${options.currency_code || 'not configured'}.`;
        const list = $('#accounting-setup-records'); list.replaceChildren();
        for (const account of accounts) {
            const item = document.createElement('span'); item.className = 'help-text'; item.textContent = `${account.code} · ${account.name} (${account.account_type})`; list.append(item);
        }
        for (const journal of journals) {
            const item = document.createElement('span'); item.className = 'help-text'; item.textContent = `${journal.code} · ${journal.name} (${journal.journal_type})`; list.append(item);
        }
        for (const journal of paymentJournals) {
            const item = document.createElement('span'); item.className = 'help-text'; item.textContent = `${journal.code} · ${journal.name} (${journal.default_account_code} → ${journal.outstanding_account_code})`; list.append(item);
        }
        if (!accounts.length && !journals.length) { const empty = document.createElement('span'); empty.className = 'help-text'; empty.textContent = 'Create company accounts and a Sales Journal here before posting invoices.'; list.append(empty); }
    }

    async function loadInvoices() {
        showMessage($('#invoicing-message'));
        const companyId = $('#invoice-company').value;
        if (!companyId) { state.invoices = []; state.totalInvoices = 0; renderInvoices(); return; }
        const query = new URLSearchParams({ company_id: companyId, state: $('#invoice-state').value, q: $('#invoice-search').value.trim(), limit: '100', offset: '0' });
        const payload = await api('/api/v1/invoicing?' + query);
        state.invoices = payload.records || []; state.totalInvoices = Number(payload.total || 0); renderInvoices();
    }

    function renderInvoices() {
        const body = $('#invoice-rows'); body.replaceChildren();
        if (!state.invoices.length) { const row=body.insertRow(); const cell=row.insertCell(); cell.colSpan=10; cell.className='empty-cell'; cell.textContent=state.totalInvoices?'No invoices or credit notes match these filters.':'No invoices yet. Create a direct invoice or invoice a confirmed Sales Order.'; }
        for (const invoice of state.invoices) {
            const row=body.insertRow(); const link=row.insertCell(); const button=document.createElement('button'); button.type='button'; button.className='contact-link'; button.textContent=invoice.name; button.addEventListener('click',()=>openInvoice(Number(invoice.id))); link.append(button);
            row.insertCell().textContent=invoice.document_type==='credit_note'?'Credit Note':'Invoice';
            const customer=row.insertCell();
            if(can('contacts.read')){const contact=document.createElement('button');contact.type='button';contact.className='text-link';contact.textContent=invoice.customer_name;contact.addEventListener('click',()=>{selectPage('contacts');openContact(Number(invoice.partner_contact_id));});customer.append(contact);}else customer.textContent=invoice.customer_name;
            row.insertCell().textContent=invoice.sales_order_name||'Direct';row.insertCell().textContent=invoice.invoice_date||'—';row.insertCell().textContent=invoice.document_type==='credit_note'?'—':invoice.due_date||'—';
            row.insertCell().textContent=formatCurrencyAmount(invoice.total_amount,invoice.currency_code);
            const status=document.createElement('span');status.className=`status-pill ${invoice.state==='posted'?'done':invoice.state==='cancelled'?'cancelled':''}`;status.textContent=invoice.state==='posted'?'Posted':invoice.state==='cancelled'?'Cancelled':'Draft';row.insertCell().append(status);
            const paymentStatus=document.createElement('span');paymentStatus.className=`status-pill ${invoice.payment_state==='paid'?'done':invoice.payment_state==='credit_open'?'credit-open':''}`;paymentStatus.textContent=invoice.payment_state==='not_applicable'?'—':invoice.payment_state==='credit_open'?'Credit open':String(invoice.payment_state||'not_paid').replaceAll('_',' ');row.insertCell().append(paymentStatus);
            const action=row.insertCell();const open=document.createElement('button');open.type='button';open.className='button button-small';open.textContent='Open';open.addEventListener('click',()=>openInvoice(Number(invoice.id)));action.append(open);
        }
        $('#invoice-total').textContent=`${state.totalInvoices} billing document${state.totalInvoices===1?'':'s'}`;
    }

    function fillDirectInvoiceContacts(customerId, selectedId = '') {
        const options=state.invoicingOptions||{};
        const choices=(options.invoice_contacts||[]).filter((row)=>Number(row.id)===Number(customerId)||Number(row.parent_contact_id)===Number(customerId));
        fillSelect($('#direct-invoice-contact'),choices,{placeholder:'Select invoice contact',keepValue:String(selectedId||customerId||''),label:(row)=>row.name});
    }

    function addDirectInvoiceLine(initial = {}) {
        const body=$('#direct-invoice-lines');const row=body.insertRow();row.className='sales-line direct-invoice-line';
        const productCell=row.insertCell();const product=document.createElement('select');product.required=true;product.setAttribute('aria-label','Invoice product');
        fillSelect(product,state.invoicingOptions?.service_products||[],{placeholder:'Select service',keepValue:String(initial.product_id||''),label:(item)=>item.name});
        const browse=document.createElement('button');browse.type='button';browse.className='button button-small shared-catalog-line-button';browse.textContent='Browse Catalog';browse.addEventListener('click',async()=>{try{await openSharedProductCatalog({channel:'invoice',companyId:$('#direct-invoice-company').value||state.invoicingOptions?.company_id,onSelect:(selected)=>{if(!(state.invoicingOptions.service_products||[]).some((item)=>Number(item.id)===Number(selected.id)))state.invoicingOptions.service_products.push(selected);if(![...product.options].some((option)=>option.value===String(selected.id)))product.add(new Option(selected.name,String(selected.id)));product.value=String(selected.id);product.dispatchEvent(new Event('change',{bubbles:true}));}});}catch(error){setFormError('direct-invoice-error',error.message);}});productCell.append(product,browse);
        const descriptionCell=row.insertCell();const description=document.createElement('input');description.name='description';description.required=true;description.maxLength=500;description.placeholder='Description';description.value=initial.description||'';descriptionCell.append(description);
        const quantityCell=row.insertCell();const quantity=document.createElement('input');quantity.name='quantity';quantity.type='number';quantity.min='0.000001';quantity.step='0.000001';quantity.required=true;quantity.value=initial.quantity||'1';quantity.setAttribute('aria-label','Quantity');quantityCell.append(quantity);
        const unitCell=row.insertCell();const unit=document.createElement('select');unit.required=true;unit.setAttribute('aria-label','Unit of Measure');unitCell.append(unit);
        const priceCell=row.insertCell();const price=document.createElement('input');price.name='unit_price';price.type='number';price.min='0';price.step='0.000001';price.required=true;price.value=initial.unit_price||'0';price.setAttribute('aria-label','Unit Price');priceCell.append(price);
        const discountCell=row.insertCell();const discount=document.createElement('input');discount.name='discount_percent';discount.type='number';discount.min='0';discount.max='100';discount.step='0.0001';discount.value=initial.discount_percent||'0';discount.setAttribute('aria-label','Discount percent');discountCell.append(discount);
        const taxCell=row.insertCell();const tax=document.createElement('select');tax.name='tax_id';tax.setAttribute('aria-label','Tax');fillSelect(tax,state.invoicingOptions?.taxes||[],{placeholder:'No Tax',keepValue:String(initial.tax_id||''),label:(item)=>`${item.name} · ${Number(item.amount)}%`});taxCell.append(tax);
        const actionCell=row.insertCell();const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.setAttribute('aria-label','Remove invoice line');remove.addEventListener('click',()=>row.remove());actionCell.append(remove);
        const setProduct=(productId,keepUnit='')=>{const selected=(state.invoicingOptions?.service_products||[]).find((item)=>Number(item.id)===Number(productId));const units=(state.invoicingOptions?.units||[]).filter((item)=>selected&&Number(item.category_id)===Number(selected.category_id));fillSelect(unit,units,{placeholder:'Select unit',keepValue:String(keepUnit||selected?.uom_id||''),label:(item)=>item.name});if(selected){if(!description.value)description.value=selected.name;if(!initial.unit_price)price.value=Number(selected.list_price||0).toFixed(currencyDecimals(state.invoicingOptions.currency_code));}};
        product.addEventListener('change',()=>setProduct(product.value,''));if(product.value)setProduct(product.value,initial.uom_id||'');else fillSelect(unit,[],{placeholder:'Select service first'});
    }

    async function openDirectInvoice(prefill = {}) {
        if(!can('invoicing.write')||!can('contacts.read'))return;
        const form=$('#direct-invoice-form');form.reset();setFormError('direct-invoice-error');$('#direct-invoice-lines').replaceChildren();
        const companyId=String(prefill.companyId||$('#invoice-company').value||state.defaultCompanyId||state.companies[0]?.id||'');
        try{
            if(!companyId)throw new Error('Choose an allowed company before creating an invoice.');
            await initializeInvoicing(companyId);
            if(prefill.catalog_product&&!(state.invoicingOptions.service_products||[]).some((item)=>Number(item.id)===Number(prefill.catalog_product.id))){state.invoicingOptions.service_products=state.invoicingOptions.service_products||[];state.invoicingOptions.service_products.push(prefill.catalog_product);}
            fillSelect($('#direct-invoice-company'),state.companies,{placeholder:'Select company',keepValue:companyId});
            fillSelect($('#direct-invoice-customer'),state.invoicingOptions.customers||[],{placeholder:'Select customer',keepValue:String(prefill.contactId||''),label:(item)=>`${item.name}${item.email?` · ${item.email}`:''}`});
            fillDirectInvoiceContacts($('#direct-invoice-customer').value,String(prefill.contactId||''));
            const today=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);form.elements.invoice_date.value=today;form.elements.due_date.value=today;
            addDirectInvoiceLine({ product_id: prefill.product_id || '' });$('#direct-invoice-dialog').showModal();
        }catch(error){setFormError('direct-invoice-error',error.message);$('#direct-invoice-dialog').showModal();}
    }

    async function saveDirectInvoice(event) {
        event.preventDefault();const form=$('#direct-invoice-form');if(!can('invoicing.write')||!can('contacts.read')||!form.reportValidity())return;setFormError('direct-invoice-error');
        const lines=$$('.direct-invoice-line',$('#direct-invoice-lines')).map((row)=>({product_id:Number($('select[aria-label="Invoice product"]',row).value),uom_id:Number($('select[aria-label="Unit of Measure"]',row).value),description:$('[name="description"]',row).value.trim(),quantity:$('[name="quantity"]',row).value,unit_price:$('[name="unit_price"]',row).value,discount_percent:$('[name="discount_percent"]',row).value,tax_id:$('[name="tax_id"]',row).value||null}));
        if(!lines.length){setFormError('direct-invoice-error','Add at least one Service line.');return;}
        const save=$('button[type="submit"]',form);save.disabled=true;
        try{
            const result=await api('/api/v1/invoicing',{method:'POST',body:JSON.stringify({company_id:Number(form.elements.company_id.value),partner_contact_id:Number(form.elements.partner_contact_id.value),invoice_contact_id:Number(form.elements.invoice_contact_id.value),invoice_date:form.elements.invoice_date.value,due_date:form.elements.due_date.value,lines})});
            $('#direct-invoice-dialog').close();$('#invoice-company').value=String(form.elements.company_id.value);await initializeInvoicing($('#invoice-company').value);await openInvoice(Number(result.invoice.id));
        }catch(error){setFormError('direct-invoice-error',error.message);}finally{save.disabled=false;}
    }

    function printInvoice() {
        const invoice=state.currentInvoice;if(!invoice)return;
        const popup=window.open('','_blank','width=1050,height=800');
        if(!popup){setFormError('invoice-form-error','Allow pop-ups for this site to print the invoice.');return;}
        const credit=invoice.document_type==='credit_note';
        const printBalance=invoice.state==='posted'?invoice.amount_residual:invoice.total_amount;
        const address=invoice.print_invoice_address||{};
        const companyAddress=[invoice.print_company_street,invoice.print_company_street2,[invoice.print_company_postal_code,invoice.print_company_city].filter(Boolean).join(' '),invoice.print_company_state,invoice.print_company_country_code].filter(Boolean);
        const contactAddress=[address.street,address.street2,[address.postal_code,address.city].filter(Boolean).join(' '),address.state,address.country_code].filter(Boolean);
        const lines=(invoice.lines||[]).map((line)=>`<tr><td>${escapeHtml(line.product_name)}</td><td>${escapeHtml(line.description)}</td><td class="num">${escapeHtml(line.quantity)} ${escapeHtml(line.uom_name||'')}</td><td class="num">${escapeHtml(formatCurrencyAmount(line.unit_price,invoice.currency_code))}</td><td class="num">${escapeHtml(line.discount_percent||'0')}%</td><td>${escapeHtml(line.tax_name_snapshot?`${line.tax_name_snapshot} · ${Number(line.tax_rate_snapshot||0)}%`:'No Tax')}</td><td class="num">${escapeHtml(formatCurrencyAmount(line.subtotal,invoice.currency_code))}</td><td class="num">${escapeHtml(formatCurrencyAmount(line.tax_amount,invoice.currency_code))}</td><td class="num">${escapeHtml(formatCurrencyAmount(line.total,invoice.currency_code))}</td></tr>`).join('');
        const payments=(invoice.payments||[]).map((payment)=>`<tr><td>${escapeHtml(payment.name)}</td><td>${escapeHtml(payment.payment_date)}</td><td>${escapeHtml(payment.journal_name)}</td><td>${escapeHtml(payment.state)}</td><td class="num">${escapeHtml(formatCurrencyAmount(payment.amount,invoice.currency_code))}</td></tr>`).join('');
        const refunds=(invoice.refunds||[]).map((refund)=>`<tr><td>${escapeHtml(refund.name)}</td><td>${escapeHtml(refund.payment_date)}</td><td>${escapeHtml(refund.journal_name)}</td><td>${escapeHtml(refund.state)}</td><td class="num">${escapeHtml(formatCurrencyAmount(refund.amount,invoice.currency_code))}</td></tr>`).join('');
        const companyName=invoice.print_company_legal_name||invoice.print_company_name||'Company';
        const customerName=invoice.invoice_contact_name||invoice.customer_name;
        const title=credit?'Customer Credit Note':'Customer Invoice';
        const details=[['Document Number',invoice.name],['Invoice Date',invoice.invoice_date],['Due Date',credit?'—':invoice.due_date],['Origin',invoice.sales_order_name||'Direct'],['Status',invoice.state],['Currency',invoice.currency_code]];
        const detailRows=details.map(([label,value])=>`<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value||'—')}</strong></div>`).join('');
        const companyContacts=[invoice.print_company_email,invoice.print_company_phone,invoice.print_company_website].filter(Boolean).map(escapeHtml).join(' · ');
        const customerContacts=[address.email||invoice.invoice_contact_email,address.phone||invoice.invoice_contact_phone].filter(Boolean).map(escapeHtml).join(' · ');
        const paymentTable=payments?`<h2>Payments</h2><table><thead><tr><th>Payment</th><th>Date</th><th>Journal</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>${payments}</tbody></table>`:'';
        const refundTable=refunds?`<h2>Refunds</h2><table><thead><tr><th>Refund</th><th>Date</th><th>Journal</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>${refunds}</tbody></table>`:'';
        popup.document.open();
        popup.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)} · ${escapeHtml(invoice.name)}</title><style>@page{size:A4;margin:14mm}*{box-sizing:border-box}body{font:14px/1.45 Arial,sans-serif;color:#1f2937;margin:0}header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #805b78;padding-bottom:18px;margin-bottom:22px}.brand h1{font-size:24px;margin:0 0 5px}.brand p,.billto p{margin:2px 0;color:#4b5563}.doctype{text-align:right}.doctype h2{font-size:22px;margin:0 0 12px;color:#805b78}.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 18px;text-align:left}.meta span{display:block;color:#6b7280;font-size:11px;text-transform:uppercase}.meta strong{display:block;margin-top:2px}.billto{margin:22px 0}.billto h3,h2{font-size:14px;margin:18px 0 8px}.status{display:inline-block;border-radius:20px;background:#edf4f4;padding:3px 10px;text-transform:capitalize}.reason{border-left:3px solid #805b78;padding:8px 12px;background:#f8f6f8}table{width:100%;border-collapse:collapse;margin:12px 0 20px}th,td{padding:8px 7px;border-bottom:1px solid #d9dee5;text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;background:#f4f6f8}.num{text-align:right;white-space:nowrap}.totals{width:min(350px,100%);margin:12px 0 20px auto}.totals div{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #e5e7eb}.totals .grand{font-size:17px;font-weight:bold;border-top:2px solid #374151;margin-top:5px;padding-top:9px}.foot{border-top:1px solid #d9dee5;margin-top:28px;padding-top:10px;color:#6b7280;font-size:11px}@media print{.no-print{display:none!important}tr{break-inside:avoid}thead{display:table-header-group}}</style></head><body><header><div class="brand"><h1>${escapeHtml(companyName)}</h1>${invoice.print_company_legal_name&&invoice.print_company_legal_name!==invoice.print_company_name?`<p>${escapeHtml(invoice.print_company_name)}</p>`:''}${invoice.print_company_tax_id?`<p>TIN: ${escapeHtml(invoice.print_company_tax_id)}</p>`:''}${companyAddress.map((part)=>`<p>${escapeHtml(part)}</p>`).join('')}${companyContacts?`<p>${companyContacts}</p>`:''}</div><div class="doctype"><h2>${escapeHtml(title)}</h2><div class="meta">${detailRows}</div></div></header><section class="billto"><h3>${credit?'Credit To':'Bill To'}</h3><strong>${escapeHtml(customerName)}</strong>${invoice.customer_name&&customerName!==invoice.customer_name?`<p>${escapeHtml(invoice.customer_name)}</p>`:''}${invoice.customer_email?`<p>${escapeHtml(invoice.customer_email)}</p>`:''}${contactAddress.map((part)=>`<p>${escapeHtml(part)}</p>`).join('')}${customerContacts?`<p>${customerContacts}</p>`:''}${invoice.partner_tax_id?`<p>TIN: ${escapeHtml(invoice.partner_tax_id)}</p>`:''}</section>${credit&&invoice.reversal_reason?`<p class="reason"><strong>Reason:</strong> ${escapeHtml(invoice.reversal_reason)}${invoice.reversal_of_invoice_name?` · Reverses ${escapeHtml(invoice.reversal_of_invoice_name)}`:''}</p>`:''}<table><thead><tr><th>Product</th><th>Description</th><th class="num">Quantity</th><th class="num">Unit Price</th><th class="num">Discount</th><th>Tax</th><th class="num">Subtotal</th><th class="num">Tax Amount</th><th class="num">Total</th></tr></thead><tbody>${lines}</tbody></table><div class="totals"><div><span>Untaxed Amount</span><strong>${escapeHtml(formatCurrencyAmount(invoice.untaxed_amount,invoice.currency_code))}</strong></div><div><span>Tax</span><strong>${escapeHtml(formatCurrencyAmount(invoice.tax_amount,invoice.currency_code))}</strong></div>${credit?`<div><span>Credit Applied</span><strong>${escapeHtml(formatCurrencyAmount(invoice.amount_applied,invoice.currency_code))}</strong></div>`:''}<div class="grand"><span>${credit?'Credit Total':'Total'}</span><strong>${escapeHtml(formatCurrencyAmount(invoice.total_amount,invoice.currency_code))}</strong></div><div><span>${credit?'Refund Registered':'Amount Paid'}</span><strong>${escapeHtml(formatCurrencyAmount(invoice.amount_paid,invoice.currency_code))}</strong></div><div><span>${credit?'Open Credit':'Amount Due'}</span><strong>${escapeHtml(formatCurrencyAmount(printBalance,invoice.currency_code))}</strong></div></div>${paymentTable}${refundTable}<p class="foot">${escapeHtml(companyName)} · ${escapeHtml(title)} · ${escapeHtml(invoice.name)}</p><script>window.addEventListener('load',()=>window.print());</script></body></html>`);
        popup.document.close();popup.focus();
    }

    async function openInvoice(id) {
        if (!can('invoicing.read')) return;
        setFormError('invoice-form-error'); $('#invoice-save-state').textContent='';
        $('#invoice-dialog-title').textContent='Loading Customer Invoice…'; $('#invoice-print').hidden=true; $('#invoice-lines').replaceChildren(); $('#invoice-journal-lines').replaceChildren(); $('#invoice-journal-panel').hidden=true;
        try {
            const invoice=(await api(`/api/v1/invoicing/${id}`)).invoice;
            if(Number(state.invoicingOptions.company_id)!==Number(invoice.company_id))await initializeInvoicing(String(invoice.company_id));
            state.currentInvoice=invoice;
            $('#invoice-print').hidden=false;
            const isCreditNote=invoice.document_type==='credit_note';$('#invoice-dialog-title').textContent=`${invoice.name} · ${invoice.customer_name}`;$('#invoice-dialog .eyebrow')?.replaceChildren(document.createTextNode(isCreditNote?'INVOICING · CUSTOMER CREDIT NOTE':'INVOICING · CUSTOMER INVOICE'));
            const summary=$('#invoice-summary');summary.replaceChildren();
            const facts=[['Document Type',isCreditNote?'Customer Credit Note':'Customer Invoice'],['Customer',invoice.customer_name],['Invoice Address',invoice.invoice_contact_name||invoice.customer_name],['Sales Order',invoice.sales_order_name||'Direct'],...(isCreditNote?[['Reverses Invoice',invoice.reversal_of_invoice_name],['Reason',invoice.reversal_reason],['Applied to Invoices',formatCurrencyAmount(invoice.amount_applied,invoice.currency_code)]]:[['Credit Applied',formatCurrencyAmount(invoice.amount_credited,invoice.currency_code)]]),['Sales Journal',invoice.journal_name],['Invoice Date',invoice.invoice_date],['Due Date',isCreditNote?'—':invoice.due_date],['Status',invoice.state],['Payment Status',invoice.payment_state==='credit_open'?'Credit Open':invoice.payment_state||'—'],[isCreditNote?'Refund Registered':'Amount Paid',formatCurrencyAmount(invoice.amount_paid,invoice.currency_code)],[isCreditNote?'Credit Balance':'Amount Due',formatCurrencyAmount(invoice.amount_residual,invoice.currency_code)],['Currency',invoice.currency_code]];
            for(const [label,value] of facts){const line=document.createElement('p');const strong=document.createElement('strong');strong.textContent=`${label}: `;line.append(strong);if(label==='Reverses Invoice'&&invoice.reversal_of_invoice_id){const sourceLink=document.createElement('button');sourceLink.type='button';sourceLink.className='text-link';sourceLink.textContent=String(value||'Open Source Invoice');sourceLink.addEventListener('click',()=>openInvoice(Number(invoice.reversal_of_invoice_id)));line.append(sourceLink);}else line.append(document.createTextNode(String(value||'—')));summary.append(line);}
            const accountingLine=document.createElement('p');const accountingLabel=document.createElement('strong');accountingLabel.textContent='Accounting Entry: ';accountingLine.append(accountingLabel,accountingMoveLink(invoice.accounting_move_id,invoice.accounting_move_name,invoice.accounting_move_state));summary.append(accountingLine);
            $('#invoice-return-column-heading').textContent=isCreditNote?'Returned Qty / Stock Return':'Available Return Qty';
            const body=$('#invoice-lines');
            for(const line of invoice.lines||[]){const row=body.insertRow();row.insertCell().textContent=line.product_name||'—';row.insertCell().textContent=line.description||'—';row.insertCell().textContent=String(line.quantity);row.insertCell().textContent=invoice.sales_order_id?(line.invoice_policy==='delivered'?'Delivered quantities':'Ordered quantities'):'Direct invoice';row.insertCell().textContent=line.uom_name||'—';row.insertCell().textContent=formatCurrencyAmount(line.unit_price,invoice.currency_code);row.insertCell().textContent=line.tax_name_snapshot?`${line.tax_name_snapshot} · ${Number(line.tax_rate_snapshot||0)}%`:'No Tax';row.insertCell().textContent=formatCurrencyAmount(line.subtotal,invoice.currency_code);row.insertCell().textContent=formatCurrencyAmount(line.tax_amount,invoice.currency_code);row.insertCell().textContent=formatCurrencyAmount(line.total,invoice.currency_code);const returnCell=row.insertCell();if(isCreditNote){returnCell.append(document.createTextNode(`${line.returned_quantity||'0.000000'} ${line.uom_name||''}${line.return_references?` · ${line.return_references}`:''}`));if(can('inventory.read')&&line.return_move_ids){for(const returnId of String(line.return_move_ids).split(',').map(Number).filter((value)=>value>0)){const openReturn=document.createElement('button');openReturn.type='button';openReturn.className='text-link';openReturn.textContent='Open Return';openReturn.addEventListener('click',async()=>{try{const companyId=Number(invoice.company_id);$('#invoice-dialog').close();selectPage('inventory');$('#inventory-company').value=String(companyId);await initializeInventory();selectInventoryView('moves');await openStockMove(returnId);}catch(error){setFormError('invoice-form-error',error.message);$('#invoice-dialog').showModal();}});returnCell.append(document.createElement('br'),openReturn);}}}else{returnCell.textContent=`${line.available_return_quantity||'0.000000'} ${line.uom_name||''}`;}const costCell=row.insertCell();if(line.cost_source){costCell.append(document.createTextNode(formatCurrencyAmount(line.cost_amount,invoice.currency_code)));const details=document.createElement('small');details.className='help-text';details.textContent=`${String(line.costing_method||'').toUpperCase()} · ${String(line.cost_source).replaceAll('_',' ')} · ${line.cost_quantity} ${line.product_uom_name||''}`;costCell.append(document.createElement('br'),details);}else costCell.textContent='—';}
            $('#invoice-untaxed').textContent=formatCurrencyAmount(invoice.untaxed_amount,invoice.currency_code);$('#invoice-tax').textContent=formatCurrencyAmount(invoice.tax_amount,invoice.currency_code);$('#invoice-total-amount').textContent=formatCurrencyAmount(invoice.total_amount,invoice.currency_code);
            if(invoice.journal_items?.length){const journalBody=$('#invoice-journal-lines');for(const item of invoice.journal_items){const row=journalBody.insertRow();row.insertCell().textContent=`${item.account_code} · ${item.account_name}`;row.insertCell().textContent=item.contact_id?invoice.customer_name:'—';row.insertCell().textContent=item.label;row.insertCell().textContent=Number(item.debit||0).toLocaleString(undefined,{minimumFractionDigits:currencyDecimals(item.currency_code),maximumFractionDigits:currencyDecimals(item.currency_code)});row.insertCell().textContent=Number(item.credit||0).toLocaleString(undefined,{minimumFractionDigits:currencyDecimals(item.currency_code),maximumFractionDigits:currencyDecimals(item.currency_code)});row.insertCell().textContent=formatCurrencyAmount(item.amount_currency,item.currency_code);}$('#invoice-journal-panel').hidden=false;}
            $('#invoice-payment-rows').replaceChildren();
            const payments=invoice.payments||[];$('#invoice-payment-panel').hidden=payments.length===0;
            for(const payment of payments){const row=$('#invoice-payment-rows').insertRow();row.insertCell().textContent=payment.name;row.insertCell().textContent=payment.payment_date;row.insertCell().textContent=payment.journal_name;row.insertCell().textContent=formatCurrencyAmount(payment.amount,invoice.currency_code);row.insertCell().textContent=payment.state;row.insertCell().textContent=payment.bank_reference||'Awaiting statement match';row.insertCell().append(accountingMoveLink(payment.accounting_move_id,payment.accounting_move_name,payment.accounting_move_state));row.insertCell().append(accountingMoveLink(payment.bank_move_id,payment.bank_move_name,payment.bank_move_state));const action=row.insertCell();if(payment.state==='in_payment'&&can('accounting.post')){const match=document.createElement('button');match.type='button';match.className='button button-small';match.textContent='Match Bank';match.addEventListener('click',()=>openBankReconcile(payment));action.append(match);}}
            const refundPanel=$('#credit-note-refund-panel'),refundRows=$('#credit-note-refund-rows');refundRows.replaceChildren();const refunds=invoice.refunds||[];refundPanel.hidden=!isCreditNote;for(const refund of refunds){const row=refundRows.insertRow();row.insertCell().textContent=refund.name;row.insertCell().textContent=refund.payment_date;row.insertCell().textContent=refund.journal_name;row.insertCell().textContent=formatCurrencyAmount(refund.amount,invoice.currency_code);row.insertCell().textContent=refund.state;row.insertCell().textContent=refund.bank_reference||'Awaiting statement match';row.insertCell().append(accountingMoveLink(refund.accounting_move_id,refund.accounting_move_name,refund.accounting_move_state));row.insertCell().append(accountingMoveLink(refund.bank_move_id,refund.bank_move_name,refund.bank_move_state));const action=row.insertCell();if(refund.state==='in_payment'&&can('accounting.reconcile')&&can('accounting.post')){const match=document.createElement('button');match.type='button';match.className='button button-small';match.textContent='Match Bank';match.addEventListener('click',()=>openCreditNoteRefundReconcile(refund));action.append(match);}}
            const creditPanel=$('#invoice-credit-note-panel'),creditRows=$('#invoice-credit-note-rows');creditRows.replaceChildren();const creditNotes=invoice.credit_notes||[];creditPanel.hidden=creditNotes.length===0;for(const credit of creditNotes){const row=creditRows.insertRow();const name=row.insertCell();const openCredit=document.createElement('button');openCredit.type='button';openCredit.className='contact-link';openCredit.textContent=credit.name;openCredit.addEventListener('click',()=>{if($('#invoice-dialog').open)$('#invoice-dialog').close();openInvoice(Number(credit.id));});name.append(openCredit);row.insertCell().textContent=credit.invoice_date;row.insertCell().textContent=formatCurrencyAmount(credit.total_amount,credit.currency_code||invoice.currency_code);row.insertCell().textContent=credit.state;row.insertCell().textContent=formatCurrencyAmount(credit.amount_applied,credit.currency_code||invoice.currency_code);row.insertCell().textContent=formatCurrencyAmount(credit.amount_paid,credit.currency_code||invoice.currency_code);row.insertCell().textContent=formatCurrencyAmount(credit.amount_residual,credit.currency_code||invoice.currency_code);const actions=row.insertCell();const open=document.createElement('button');open.type='button';open.className='button button-small';open.textContent='Open';open.addEventListener('click',()=>{if($('#invoice-dialog').open)$('#invoice-dialog').close();openInvoice(Number(credit.id));});actions.append(open);}
            const applicationPanel=$('#invoice-credit-application-panel'),applicationRows=$('#invoice-credit-application-rows');applicationRows.replaceChildren();const creditApplications=invoice.credit_applications||[];applicationPanel.hidden=creditApplications.length===0;for(const application of creditApplications){const row=applicationRows.insertRow();const linkCell=(id,label)=>{const cell=row.insertCell();const link=document.createElement('button');link.type='button';link.className='contact-link';link.textContent=label||'—';link.disabled=!Number(id);link.addEventListener('click',()=>{if($('#invoice-dialog').open)$('#invoice-dialog').close();openInvoice(Number(id));});cell.append(link);};linkCell(application.credit_note_id,application.credit_note_name);linkCell(application.source_invoice_id,application.source_invoice_name);linkCell(application.target_invoice_id,application.target_invoice_name);row.insertCell().textContent=formatCurrencyAmount(application.amount,invoice.currency_code);row.insertCell().textContent=application.applied_at;}
            $('#invoice-create-credit-note').hidden=isCreditNote||invoice.state!=='posted'||!can('invoicing.write')||!(invoice.lines||[]).some((line)=>Number(line.creditable_quantity)>0);
            $('#invoice-discard-credit-note').hidden=!isCreditNote||invoice.state!=='draft'||!can('invoicing.write');
            const refundJournals=(state.invoicingOptions.payment_journals||[]).filter((journal)=>Number(journal.outstanding_payments_account_id)>0);$('#invoice-register-credit-note-refund').hidden=!isCreditNote||invoice.state!=='posted'||Number(invoice.amount_residual||0)<=0||!can('invoicing.write')||!can('accounting.post');$('#invoice-register-credit-note-refund').disabled=refundJournals.length===0;$('#invoice-register-credit-note-refund').title=refundJournals.length?'':'Configure an Outstanding Payments account on a Bank/Cash journal first.';$('#invoice-apply-credit-note').hidden=!isCreditNote||invoice.state!=='posted'||Number(invoice.amount_residual||0)<=0||!can('invoicing.write')||!can('accounting.reconcile')||!can('accounting.post');
            $('#invoice-register-payment').hidden=isCreditNote||invoice.state!=='posted'||Number(invoice.amount_residual||0)<=0||!can('invoicing.write')||!can('accounting.post');
            $('#invoice-register-payment').disabled=!(state.invoicingOptions.payment_journals||[]).some((journal)=>Number(journal.outstanding_receipts_account_id)>0);
            $('#invoice-post').textContent=isCreditNote?'Post Credit Note':'Post Invoice';$('#invoice-post').hidden=invoice.state!=='draft'||!can('invoicing.post')||!can('accounting.post');$('#invoice-post').disabled=false;
            $('#invoice-dialog').showModal();
        }catch(error){setFormError('invoice-form-error',error.message);$('#invoice-dialog').showModal();}
    }

    async function postInvoice() {
        const invoice=state.currentInvoice;if(!invoice||!can('invoicing.post')||!can('accounting.post'))return;
        $('#invoice-post').disabled=true;setFormError('invoice-form-error');
        try{await api(`/api/v1/invoicing/${invoice.id}/post`,{method:'POST',body:JSON.stringify({})});await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('invoice-form-error',error.message);$('#invoice-post').disabled=false;}
    }

    async function discardCreditNote() {
        const invoice=state.currentInvoice;if(!invoice||invoice.document_type!=='credit_note'||invoice.state!=='draft'||!can('invoicing.write'))return;
        if(!window.confirm(`Discard draft credit note ${invoice.name}? It will remain in the audit history.`))return;
        const button=$('#invoice-discard-credit-note');button.disabled=true;setFormError('invoice-form-error');
        try{await api(`/api/v1/invoicing/${invoice.id}/cancel-credit-note`,{method:'POST',body:JSON.stringify({})});await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('invoice-form-error',error.message);}finally{button.disabled=false;}
    }

    async function openCustomerCreditApplication() {
        const note=state.currentInvoice;if(!note||note.document_type!=='credit_note'||note.state!=='posted'||Number(note.amount_residual)<=0||!can('invoicing.write')||!can('accounting.reconcile')||!can('accounting.post'))return;
        const form=$('#customer-credit-apply-form');form.reset();$('#customer-credit-apply-search').value='';form.dataset.creditNoteId=String(note.id);setFormError('customer-credit-apply-error');state.customerCreditApplication={note,invoices:[],searchSequence:0};
        $('#customer-credit-apply-info').textContent=`${note.name} · ${note.customer_name} · Open credit ${formatCurrencyAmount(note.amount_residual,note.currency_code)}`;fillSelect($('#customer-credit-apply-invoice'),[],{placeholder:'Search for a posted Customer Invoice'});$('#customer-credit-apply-dialog').showModal();await searchCustomerCreditInvoices();
    }
    async function searchCustomerCreditInvoices() {
        const data=state.customerCreditApplication;if(!data)return;const note=data.note;const sequence=++data.searchSequence;const query=new URLSearchParams({company_id:String(note.company_id),partner_contact_id:String(note.partner_contact_id),state:'posted',q:$('#customer-credit-apply-search').value.trim(),limit:'100',offset:'0'});setFormError('customer-credit-apply-error');
        try{const result=await api('/api/v1/invoicing?'+query);if(state.customerCreditApplication!==data||data.searchSequence!==sequence)return;const invoices=(result.records||[]).filter((invoice)=>invoice.document_type==='invoice'&&Number(invoice.amount_residual)>0&&invoice.currency_code===note.currency_code);const select=$('#customer-credit-apply-invoice');select.value='';data.invoices=invoices;fillSelect(select,invoices,{placeholder:'Select posted Customer Invoice',label:(invoice)=>`${invoice.name} · Open ${formatCurrencyAmount(invoice.amount_residual,invoice.currency_code)}`});updateCustomerCreditApplicationAmount();if(!invoices.length)setFormError('customer-credit-apply-error','No matching same-customer posted invoices have an open balance in this currency. Search by invoice number.');}catch(error){if(state.customerCreditApplication===data&&data.searchSequence===sequence)setFormError('customer-credit-apply-error',error.message);}
    }
    function updateCustomerCreditApplicationAmount() {
        const form=$('#customer-credit-apply-form'),data=state.customerCreditApplication;if(!data)return;const invoice=data.invoices.find((row)=>Number(row.id)===Number(form.elements.customer_invoice_id.value));const input=form.elements.amount;if(!invoice){input.value='';input.removeAttribute('max');return;}const places=currencyDecimals(invoice.currency_code);const max=Math.min(Number(data.note.amount_residual),Number(invoice.amount_residual));input.min=(1/(10**places)).toFixed(places);input.max=String(max);input.step=places?`0.${'0'.repeat(places-1)}1`:'1';input.value=max.toFixed(places);
    }
    async function applyCustomerCredit(event) {
        event.preventDefault();const form=$('#customer-credit-apply-form'),data=state.customerCreditApplication;if(!data||!form.reportValidity())return;setFormError('customer-credit-apply-error');const submit=$('button[type="submit"]',form);submit.disabled=true;
        try{await api(`/api/v1/invoicing/credit-notes/${form.dataset.creditNoteId}/applications`,{method:'POST',body:JSON.stringify({customer_invoice_id:Number(form.elements.customer_invoice_id.value),amount:form.elements.amount.value})});$('#customer-credit-apply-dialog').close();await loadInvoices();await openInvoice(Number(data.note.id));}
        catch(error){setFormError('customer-credit-apply-error',error.message);}finally{submit.disabled=false;}
    }
    function openCreditNoteRefund() {
        const invoice=state.currentInvoice;if(!invoice||invoice.document_type!=='credit_note'||invoice.state!=='posted'||Number(invoice.amount_residual||0)<=0||!can('invoicing.write')||!can('accounting.post'))return;
        const journals=(state.invoicingOptions.payment_journals||[]).filter((journal)=>Number(journal.outstanding_payments_account_id)>0);if(!journals.length){setFormError('invoice-form-error','Configure a Bank/Cash journal with an Outstanding Payments account before refunding this credit note.');return;}
        const form=$('#credit-note-refund-form');form.reset();fillSelect($('#credit-note-refund-journal'),journals,{placeholder:'Select Bank / Cash Journal',label:(row)=>`${row.code} · ${row.name}`});$('#credit-note-refund-date').value=accountingToday();$('#credit-note-refund-amount').max=String(invoice.amount_residual);$('#credit-note-refund-amount').step=`0.${'0'.repeat(Math.max(0,currencyDecimals(invoice.currency_code)-1))}1`;$('#credit-note-refund-amount').value=Number(invoice.amount_residual).toFixed(currencyDecimals(invoice.currency_code));setFormError('credit-note-refund-error');$('#credit-note-refund-dialog').showModal();
    }

    async function registerCreditNoteRefund(event) {
        event.preventDefault();const invoice=state.currentInvoice;const form=$('#credit-note-refund-form');if(!invoice||invoice.document_type!=='credit_note'||!can('invoicing.write')||!can('accounting.post')||!form.reportValidity())return;
        setFormError('credit-note-refund-error');const submit=$('button[type="submit"]',form);submit.disabled=true;try{await api(`/api/v1/invoicing/${invoice.id}/refunds`,{method:'POST',body:JSON.stringify({journal_id:Number(form.elements.journal_id.value),payment_date:form.elements.payment_date.value,amount:form.elements.amount.value,reference:form.elements.reference.value.trim()})});$('#credit-note-refund-dialog').close();await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('credit-note-refund-error',error.message);}finally{submit.disabled=false;}
    }

    function openCreditNoteRefundReconcile(refund) {
        const invoice=state.currentInvoice;if(!invoice||invoice.document_type!=='credit_note'||!refund||refund.state!=='in_payment')return;state.currentRefund=refund;const form=$('#credit-note-refund-reconcile-form');form.reset();$('#credit-note-refund-reconcile-summary').textContent=`${refund.name} · ${refund.journal_name} · ${formatCurrencyAmount(refund.amount,invoice.currency_code)}`;$('#credit-note-refund-transaction-date').value=accountingToday();form.elements.reference.value=refund.reference||refund.name;$('#credit-note-refund-transaction-amount').value=(-Number(refund.amount)).toFixed(currencyDecimals(invoice.currency_code));setFormError('credit-note-refund-reconcile-error');$('#credit-note-refund-reconcile-dialog').showModal();
    }

    async function reconcileCreditNoteRefund(event) {
        event.preventDefault();const invoice=state.currentInvoice;const refund=state.currentRefund;const form=$('#credit-note-refund-reconcile-form');if(!invoice||!refund||!can('accounting.post')||!form.reportValidity())return;
        setFormError('credit-note-refund-reconcile-error');try{await api(`/api/v1/invoicing/refunds/${refund.id}/reconcile`,{method:'POST',body:JSON.stringify({reference:form.elements.reference.value.trim(),transaction_date:form.elements.transaction_date.value,amount:form.elements.amount.value})});$('#credit-note-refund-reconcile-dialog').close();await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('credit-note-refund-reconcile-error',error.message);}
    }

    function openCreditNoteDialog() {
        const invoice=state.currentInvoice;if(!invoice||invoice.document_type==='credit_note'||invoice.state!=='posted'||!can('invoicing.write'))return;
        const form=$('#credit-note-form');form.reset();$('#credit-note-date').value=accountingToday();$('#credit-note-source').textContent=`Source invoice ${invoice.name} · ${invoice.customer_name} · ${formatCurrencyAmount(invoice.total_amount,invoice.currency_code)}`;const body=$('#credit-note-lines');body.replaceChildren();
        for(const line of invoice.lines||[]){const remaining=Number(line.creditable_quantity||0);if(remaining<=0)continue;const row=body.insertRow();row.dataset.lineId=String(line.id);row.insertCell().textContent=line.product_name||'—';row.insertCell().textContent=line.description||'—';row.insertCell().textContent=String(line.quantity);row.insertCell().textContent=String(line.credited_quantity||'0.000000');const quantity=row.insertCell();const input=document.createElement('input');input.className='credit-quantity';input.type='number';input.min='0';input.max=String(line.creditable_quantity);input.step='0.000001';input.value=String(line.creditable_quantity);input.setAttribute('aria-label',`Credit quantity for ${line.description||line.product_name}`);quantity.append(input);const returnCell=row.insertCell();const available=Math.max(0,Number(line.available_return_quantity||0));const help=document.createElement('small');help.className='help-text';help.textContent=`Available: ${line.available_return_quantity||'0.000000'} ${line.uom_name||''}`;const returnInput=document.createElement('input');returnInput.className='return-quantity';returnInput.type='number';returnInput.min='0';returnInput.max=String(Math.min(remaining,available));returnInput.step='0.000001';returnInput.value='0';returnInput.disabled=available<=0;returnInput.setAttribute('aria-label',`Physically returned quantity for ${line.description||line.product_name}`);input.addEventListener('input',()=>{const limit=Math.min(Math.max(0,Number(input.value||0)),available);returnInput.max=String(limit);if(Number(returnInput.value)>limit)returnInput.value=String(limit);});returnCell.append(help,returnInput);row.insertCell().textContent=line.uom_name||'—';row.insertCell().textContent=line.tax_name_snapshot?`${line.tax_name_snapshot} · ${Number(line.tax_rate_snapshot||0)}%`:'No Tax';}
        setFormError('credit-note-form-error');$('#credit-note-dialog').showModal();
    }

    async function createCreditNote(event) {
        event.preventDefault();const invoice=state.currentInvoice;const form=$('#credit-note-form');if(!invoice||!form.reportValidity())return;const lines=[];for(const row of $$('#credit-note-lines tr')){const quantity=$('.credit-quantity',row).value.trim();const returnQuantity=$('.return-quantity',row).value.trim();if(quantity!==''&&Number(quantity)>0)lines.push({invoice_line_id:Number(row.dataset.lineId),quantity,return_quantity:returnQuantity||'0'});}if(!lines.length){setFormError('credit-note-form-error','Enter a positive credit quantity on at least one invoice line.');return;}
        setFormError('credit-note-form-error');const save=$('button[type="submit"]',form);save.disabled=true;try{const result=await api(`/api/v1/invoicing/${invoice.id}/credit-notes`,{method:'POST',body:JSON.stringify({credit_note_date:form.elements.credit_note_date.value,reason:form.elements.reason.value.trim(),lines})});$('#credit-note-dialog').close();await loadInvoices();await openInvoice(Number(result.invoice.id));}
        catch(error){setFormError('credit-note-form-error',error.message);}finally{save.disabled=false;}
    }

    function syncJournalSetupFields() {
        const requiresPaymentAccounts=['bank','cash'].includes($('#journal-setup-type').value);
        $('#payment-journal-accounts').hidden=!requiresPaymentAccounts;
        $('#journal-default-account').required=requiresPaymentAccounts;
        $('#journal-outstanding-account').required=requiresPaymentAccounts;
        $('#journal-outstanding-payments-account').required=false;
    }

    function openRegisterPayment() {
        const invoice=state.currentInvoice;if(!invoice||invoice.state!=='posted'||Number(invoice.amount_residual||0)<=0)return;
        const journals=(state.invoicingOptions.payment_journals||[]).filter((journal)=>Number(journal.outstanding_receipts_account_id)>0);if(!journals.length){setFormError('invoice-form-error','Create a Bank / Cash Journal with Bank/Cash and Outstanding Receipts accounts before registering payment.');return;}
        const form=$('#payment-form');form.reset();fillSelect($('#payment-journal'),journals,{placeholder:'Select Bank / Cash Journal',label:(row)=>`${row.code} · ${row.name}`});
        $('#payment-date').value=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);$('#payment-amount').step=`0.${'0'.repeat(Math.max(0,currencyDecimals(invoice.currency_code)-1))}1`;
        $('#payment-amount').value=Number(invoice.amount_residual).toFixed(currencyDecimals(invoice.currency_code));setFormError('payment-form-error');$('#payment-dialog').showModal();
    }

    async function registerCustomerPayment(event) {
        event.preventDefault();const invoice=state.currentInvoice;const form=$('#payment-form');if(!invoice||!can('invoicing.write')||!can('accounting.post')||!form.reportValidity())return;
        setFormError('payment-form-error');try{await api(`/api/v1/invoicing/${invoice.id}/payments`,{method:'POST',body:JSON.stringify({journal_id:Number(form.elements.journal_id.value),payment_date:form.elements.payment_date.value,amount:form.elements.amount.value,reference:form.elements.reference.value.trim()})});$('#payment-dialog').close();await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('payment-form-error',error.message);}
    }

    function openBankReconcile(payment) {
        const invoice=state.currentInvoice;if(!invoice||!payment||payment.state!=='in_payment')return;state.currentPayment=payment;
        const form=$('#bank-reconcile-form');form.reset();$('#bank-reconcile-payment').textContent=`${payment.name} · ${payment.journal_name} · ${formatCurrencyAmount(payment.amount,invoice.currency_code)}`;
        form.elements.amount.value=Number(payment.amount).toFixed(currencyDecimals(invoice.currency_code));form.elements.transaction_date.value=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);setFormError('bank-reconcile-error');$('#bank-reconcile-dialog').showModal();
    }

    async function reconcileCustomerPayment(event) {
        event.preventDefault();const invoice=state.currentInvoice;const payment=state.currentPayment;const form=$('#bank-reconcile-form');if(!invoice||!payment||!can('accounting.post')||!form.reportValidity())return;
        setFormError('bank-reconcile-error');try{await api(`/api/v1/invoicing/payments/${payment.id}/reconcile`,{method:'POST',body:JSON.stringify({reference:form.elements.reference.value.trim(),transaction_date:form.elements.transaction_date.value,amount:form.elements.amount.value})});$('#bank-reconcile-dialog').close();await loadInvoices();await openInvoice(Number(invoice.id));}
        catch(error){setFormError('bank-reconcile-error',error.message);}
    }

    function accountingToday() { return new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
    function formatAccountingAmount(value, currency) {
        const raw=String(value??'0');const negative=raw.startsWith('-');const unsigned=negative?raw.slice(1):raw;const [whole='0',fraction='']=unsigned.split('.');
        const grouped=(whole.replace(/^0+(?=\d)/,'')||'0').replace(/\B(?=(\d{3})+(?!\d))/g,',');
        return `${currency} ${negative&&unsigned!=='0'?'-':''}${grouped}${fraction?'.'+fraction:''}`;
    }
    function accountingMoveLink(moveId, moveName, moveState = '') {
        const label = `${moveName || 'Journal Entry'}${moveState ? ` · ${moveState}` : ''}`;
        if (!moveId || !can('accounting.read')) { const text = document.createElement('span'); text.textContent = moveId ? 'Restricted' : '—'; return text; }
        const button = document.createElement('button'); button.type = 'button'; button.className = 'link-button'; button.textContent = label;
        button.addEventListener('click', () => openAccountingMove(Number(moveId)));
        return button;
    }
    async function initializeAccounting(companyId = '') {
        syncAccountingWorkspaceAccess();
        const companySelect=$('#accounting-company');const selected=String(companyId||companySelect.value||state.defaultCompanyId||state.companies[0]?.id||'');
        const previousCompany=String(state.accountingOptions?.company?.id||'');if(previousCompany&&previousCompany!==selected){$('#accounting-account').value='';$('#bank-statement-detail').hidden=true;state.bankStatementDetail=null;state.currentStatementLine=null;if($('#bank-statement-match-dialog').open)$('#bank-statement-match-dialog').close();}
        clearAccountingReport();
        fillSelect(companySelect,state.companies,{placeholder:'Select company',keepValue:selected});
        if(selected)companySelect.value=selected;
        if(!$('#accounting-date-to').value)$('#accounting-date-to').value=accountingToday();
        if(!$('#accounting-date-from').value)$('#accounting-date-from').value=`${$('#accounting-date-to').value.slice(0,4)}-01-01`;
        if(!selected){showMessage($('#accounting-message'),'Select an allowed company to load its chart of accounts.',true);return;}
        const [result,entryOptions]=await Promise.all([api(`/api/v1/accounting/options?company_id=${encodeURIComponent(selected)}`),api(`/api/v1/accounting/manual-entries/options?company_id=${encodeURIComponent(selected)}`)]);state.accountingOptions=result.options;state.manualEntryOptions=entryOptions.options||{};
        const companyOption=[...companySelect.options].find((option)=>option.value===selected);if(companyOption&&companyOption.text.startsWith('Company '))companyOption.text=result.options.company.name;
        fillSelect($('#accounting-account'),result.options.accounts||[],{placeholder:'Select an account',label:(row)=>`${row.code} · ${row.name}`});
        fillSelect($('#accounting-entry-company'),state.companies,{placeholder:'Select company',keepValue:selected});$('#accounting-entry-company').value=selected;
        fillSelect($('#accounting-entry-journal'),state.manualEntryOptions.journals||[],{placeholder:'Select General Journal',label:(row)=>`${row.code} · ${row.name}`});
        syncAccountingReportControls();
        if(state.accountingView==='configuration')await initializeAccountingSetup(selected);
        if(state.accountingView==='reports')await loadAccountingReport(0,false);else if(state.accountingView==='entries')await loadManualJournalEntries(0,false);else if(state.accountingView==='bank-statements')await loadBankStatements(0,false);
    }
    function syncAccountingReportControls() {
        const report=$('#accounting-report-type').value;$('#accounting-account-wrap').hidden=report!=='general_ledger';
        $('#accounting-from-wrap').hidden=!['general_ledger','profit_loss'].includes(report);
        state.accountingReport=report;
    }
    function clearAccountingReport() {
        state.accountingRecords=[];state.accountingTotal=0;
        $('#accounting-report-title').textContent='';$('#accounting-summary').replaceChildren();
        $('#accounting-report-head').replaceChildren();$('#accounting-report-rows').replaceChildren();
        $('#accounting-report-count').textContent='0 account lines';
        const more=$('#accounting-report-more');more.hidden=true;more.disabled=false;more.textContent='Load more';
    }
    async function loadAccountingReport(offset=0,append=false) {
        if(!can('accounting.read'))return;const companyId=$('#accounting-company').value;const report=$('#accounting-report-type').value;
        if(!companyId){clearAccountingReport();showMessage($('#accounting-message'),'Select an allowed company to run a report.',true);return;}
        if(report==='general_ledger'&&!$('#accounting-account').value){clearAccountingReport();showMessage($('#accounting-message'),'Select an account to open its General Ledger.',true);return;}
        const query=new URLSearchParams({company_id:companyId,report,date_to:$('#accounting-date-to').value,limit:'100',offset:String(offset)});
        if(['general_ledger','profit_loss'].includes(report))query.set('date_from',$('#accounting-date-from').value);
        if(report==='general_ledger')query.set('account_id',$('#accounting-account').value);
        const button=$('#accounting-run');button.disabled=true;showMessage($('#accounting-message'),'');
        if(!append){clearAccountingReport();$('#accounting-summary').textContent='Loading report…';}
        try{
            const result=await api(`/api/v1/accounting/reports?${query}`);state.accountingOptions.company=result.company;
            state.accountingRecords=append?[...state.accountingRecords,...(result.records||[])]:result.records||[];state.accountingTotal=Number(result.total||0);
            renderAccountingReport(result);
        }catch(error){showMessage($('#accounting-message'),error.message,true);if(!append)$('#accounting-summary').textContent='The report could not be loaded.';}
        finally{button.disabled=false;if(append)$('#accounting-report-more').disabled=false;}
    }
    function renderAccountingReport(result) {
        const report=result.report;const currency=result.company.currency_code;const dates=report==='trial_balance'||report==='balance_sheet'?`As of ${result.date_to}`:`${result.date_from} to ${result.date_to}`;
        const titles={trial_balance:'Trial Balance',general_ledger:`General Ledger · ${result.account?.code||''} ${result.account?.name||''}`,profit_loss:'Profit and Loss',balance_sheet:'Balance Sheet'};
        $('#accounting-report-title').textContent=`${titles[report]} · ${result.company.name} · ${dates}`;
        const summary=$('#accounting-summary');summary.replaceChildren();
        const total=result.totals||{};const values=report==='trial_balance'?[['Debit balances',total.debit_balance],['Credit balances',total.credit_balance]]:report==='general_ledger'?[['Opening balance',total.opening_balance],['Period debits',total.period_debit],['Period credits',total.period_credit],['Closing balance',total.closing_balance]]:report==='profit_loss'?[['Income',total.income],['Expenses',total.expenses],['Net profit',total.net_profit]]:[['Assets',total.assets],['Liabilities',total.liabilities],['Equity incl. unclosed earnings',total.equity_with_unclosed_earnings],['Balance difference',total.balance_difference]];
        for(const [label,value] of values){const metric=document.createElement('div');metric.className='accounting-summary-metric';const caption=document.createElement('span');caption.textContent=label;const amount=document.createElement('strong');amount.textContent=formatAccountingAmount(value,currency);metric.append(caption,amount);summary.append(metric);}
        const columns=report==='trial_balance'?[['code','Account Code'],['name','Account'],['account_type','Type'],['debit_balance','Debit Balance'],['credit_balance','Credit Balance']]:report==='general_ledger'?[['accounting_date','Date'],['journal_code','Journal'],['move_name','Entry'],['reference','Reference'],['contact_name','Contact'],['label','Label'],['debit','Debit'],['credit','Credit'],['running_balance','Balance']]:report==='profit_loss'?[['code','Account Code'],['name','Account'],['account_type','Type'],['amount','Amount']]:[['code','Account Code'],['name','Account'],['section','Section'],['balance','Balance']];
        const head=$('#accounting-report-head');head.replaceChildren();const headerRow=head.insertRow();for(const [,label] of columns){const cell=document.createElement('th');cell.textContent=label;headerRow.append(cell);}
        const body=$('#accounting-report-rows');body.replaceChildren();
        if(!state.accountingRecords.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=columns.length;cell.className='empty-cell';cell.textContent=report==='trial_balance'?'No posted balances as of this date.':'No posted journal entries match this report.';}
        for(const record of state.accountingRecords){const row=body.insertRow();for(const [key] of columns){const cell=row.insertCell();if(report==='general_ledger'&&key==='move_name'){cell.append(accountingMoveLink(record.move_id,record.move_name));continue;}let value=record[key]??'—';if(['debit_balance','credit_balance','amount','balance','debit','credit','running_balance'].includes(key))value=formatAccountingAmount(value,currency);else if(key==='account_type')value=String(value).replaceAll('_',' ');cell.textContent=String(value);}}
        $('#accounting-report-count').textContent=`${state.accountingRecords.length} of ${state.accountingTotal} account line${state.accountingTotal===1?'':'s'}`;
        const more=$('#accounting-report-more');more.hidden=state.accountingRecords.length>=state.accountingTotal;more.disabled=false;more.textContent=`Load more (${state.accountingTotal-state.accountingRecords.length} remaining)`;
    }

    function setAccountingView(view, load = true) {
        if(view==='configuration'&&(!can('accounting.write')||!can('invoicing.read')))return;
        state.accountingView=['overview','entries','bank-statements','configuration'].includes(view)?view:'reports';
        showMessage($('#accounting-message'));
        $('#accounting-overview-view').hidden=state.accountingView!=='overview';$('#accounting-reports-view').hidden=state.accountingView!=='reports';$('#accounting-entries-view').hidden=state.accountingView!=='entries';$('#accounting-bank-statements-view').hidden=state.accountingView!=='bank-statements';$('#accounting-configuration-view').hidden=state.accountingView!=='configuration';
        $$('.accounting-module-nav-button[data-accounting-view]').forEach((button)=>button.classList.toggle('active',button.dataset.accountingView===state.accountingView));
        const addEntry=$('#accounting-new-entry-action');if(addEntry)addEntry.hidden=state.accountingView!=='entries';
        if (!load) return;
        if(state.accountingView==='entries')loadManualJournalEntries(0,false).catch((error)=>showMessage($('#accounting-entry-message'),error.message,true));else if(state.accountingView==='bank-statements')loadBankStatements(0,false).catch((error)=>showMessage($('#bank-statement-message'),error.message,true));else if(state.accountingView==='reports')loadAccountingReport(0,false);else if(state.accountingView==='configuration')initializeAccountingSetup($('#accounting-company').value).catch((error)=>showMessage($('#accounting-message'),error.message,true));
    }

    function syncAccountingWorkspaceAccess() {
        $$('[data-accounting-permission]').forEach((button)=>{
            const permission=button.dataset.accountingPermission;
            const configuration=button.dataset.accountingView==='configuration';
            button.hidden=!can(permission)||(configuration&&!can('invoicing.read'));
        });
    }

    function openAccountingDestination(destination) {
        const companyId=String($('#accounting-company').value||state.defaultCompanyId||'');
        if(destination==='contacts-customer'||destination==='contacts-supplier'){
            if(!can('contacts.read'))return;
            $('#contact-role').value=destination==='contacts-customer'?'customer':'supplier';
            selectPage('contacts');
            return;
        }
        if(destination==='purchase'){
            if(!can('purchase.read'))return;
            if(companyId)$('#purchase-company').value=companyId;
            selectPage('purchase');
            return;
        }
        if(destination==='invoicing'){
            if(!can('invoicing.read'))return;
            if(companyId)$('#invoice-company').value=companyId;
            selectPage('invoicing');
            return;
        }
    }

    async function loadBankStatementOptions(companyId) {
        const result=await api(`/api/v1/accounting/bank-statements/options?company_id=${encodeURIComponent(companyId)}`);state.bankStatementOptions=result.options||{};
        fillSelect($('#bank-statement-journal'),state.bankStatementOptions.journals||[],{placeholder:'Select Bank / Cash Journal',label:(row)=>`${row.code} · ${row.name}`});
        fillSelect($('#bank-statement-categorize-account'),state.bankStatementOptions.reconciliation_accounts||[],{placeholder:'Select counterpart account',label:(row)=>`${row.code} · ${row.name}`});
        const form=$('#bank-statement-import-form');form.elements.opening_balance.placeholder=state.bankStatementOptions.company?.currency_code||'0.00';form.elements.closing_balance.placeholder=state.bankStatementOptions.company?.currency_code||'0.00';
        const canImport=can('accounting.reconcile');$('#bank-statement-import').disabled=!canImport;$('#bank-statement-journal').disabled=!canImport;
    }

    async function loadBankStatements(offset=0,append=false) {
        if(!can('accounting.read'))return;const companyId=$('#accounting-company').value;if(!companyId){state.bankStatements=[];state.bankStatementTotal=0;renderBankStatements();showMessage($('#bank-statement-message'),'Select a company to load bank statements.',true);return;}
        if(String(state.bankStatementOptions.company?.id||'')!==String(companyId))await loadBankStatementOptions(companyId);
        const query=new URLSearchParams({company_id:companyId,limit:'50',offset:String(offset)});if(!append){showMessage($('#bank-statement-message'));$('#bank-statement-rows').innerHTML='<tr><td colspan="8" class="empty-cell">Loading bank statements…</td></tr>';}
        try{const result=await api(`/api/v1/accounting/bank-statements?${query}`);state.bankStatements=append?[...state.bankStatements,...(result.records||[])]:result.records||[];state.bankStatementTotal=Number(result.total||0);renderBankStatements();}
        catch(error){showMessage($('#bank-statement-message'),error.message,true);if(!append)state.bankStatements=[];renderBankStatements();throw error;}
    }

    function renderBankStatements() {
        const body=$('#bank-statement-rows');body.replaceChildren();
        if(!state.bankStatements.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=8;cell.className='empty-cell';cell.textContent='No bank statements have been imported for this company.';}
        for(const statement of state.bankStatements){const row=body.insertRow();const name=row.insertCell();const open=document.createElement('button');open.type='button';open.className='contact-link';open.textContent=statement.name;open.addEventListener('click',()=>openBankStatement(Number(statement.id)));name.append(open);row.insertCell().textContent=`${statement.journal_code} · ${statement.journal_name}`;row.insertCell().textContent=`${statement.statement_date_from} – ${statement.statement_date_to}`;row.insertCell().textContent=formatAccountingAmount(statement.opening_balance,statement.currency_code);row.insertCell().textContent=formatAccountingAmount(statement.closing_balance,statement.currency_code);row.insertCell().textContent=String(statement.line_count);row.insertCell().textContent=String(statement.unmatched_count);const stateCell=row.insertCell();const pill=document.createElement('span');pill.className=`status-pill ${statement.state==='reconciled'?'done':''}`;pill.textContent=statement.state==='reconciled'?'Reconciled':'Imported';stateCell.append(pill);}
        $('#bank-statement-count').textContent=`${state.bankStatements.length} of ${state.bankStatementTotal} statement${state.bankStatementTotal===1?'':'s'}`;const more=$('#bank-statement-more');more.hidden=state.bankStatements.length>=state.bankStatementTotal;more.disabled=false;more.textContent=`Load more (${state.bankStatementTotal-state.bankStatements.length} remaining)`;
    }

    async function openBankStatement(id, append = false, focusLineId = null) {
        const current = state.bankStatementDetail;
        const offset = append ? Number(current?.nextOffset || 0) : 0;
        const query = new URLSearchParams({ limit: '100', offset: String(offset) });
        if (focusLineId) query.set('line_id', String(focusLineId));
        const result = await api(`/api/v1/accounting/bank-statements/${id}?${query}`);
        const statement = result.statement;
        const rows = result.lines || [];
        const nextOffset = Number(result.next_offset ?? (Number(result.offset || offset) + rows.length));
        state.bankStatementDetail = append && current?.statement?.id === statement.id
            ? { ...current, lines: [...current.lines, ...rows], nextOffset }
            : {
                statement,
                lines: rows,
                startOffset: Number(result.offset || 0),
                nextOffset,
                focusLineId: Number(result.focus_line_id || focusLineId || 0),
            };
        state.bankStatementLineTotal = Number(statement.line_count || 0);
        $('#bank-statement-detail').hidden = false;
        renderBankStatementDetail();
    }

    function renderBankStatementDetail() {
        const detail = state.bankStatementDetail;
        if (!detail) return;
        const statement = detail.statement;
        $('#bank-statement-detail-title').textContent = `${statement.name} · ${statement.state === 'reconciled' ? 'Reconciled' : 'Imported'}`;
        $('#bank-statement-detail-summary').textContent = `${statement.source_file_name} · ${statement.journal_code} ${statement.journal_name} · ${statement.statement_date_from} to ${statement.statement_date_to} · Opening ${formatAccountingAmount(statement.opening_balance, statement.currency_code)} · Closing ${formatAccountingAmount(statement.closing_balance, statement.currency_code)}`;
        const body = $('#bank-statement-line-rows');
        body.replaceChildren();
        const mayMatch = can('accounting.reconcile') && can('accounting.post');
        let focusRow = null;
        for (const line of detail.lines) {
            const row = body.insertRow();
            row.dataset.statementLineId = String(line.id);
            if (Number(line.id) === Number(detail.focusLineId)) {
                row.classList.add('source-line-highlight');
                focusRow = row;
            }
            row.insertCell().textContent = String(line.line_number);
            row.insertCell().textContent = line.transaction_date;
            row.insertCell().textContent = line.reference || '—';
            row.insertCell().textContent = line.description || '—';
            row.insertCell().textContent = formatAccountingAmount(line.amount, line.currency_code);
            row.insertCell().textContent = line.matched_type
                ? (line.matched_type === 'account' ? `Account · ${line.document_name || ''}` : `${line.partner_name || 'Contact'} · ${line.document_name || line.payment_name || ''}`)
                : '—';
            const entryCell = row.insertCell();
            entryCell.append(accountingMoveLink(line.accounting_move_id, line.accounting_move_name, line.accounting_move_state));
            const action = row.insertCell();
            if (line.matched_type) {
                action.textContent = line.matched_type === 'account' ? 'Categorized' : 'Matched';
            } else if (mayMatch && statement.state === 'imported') {
                const actions = document.createElement('div');
                actions.className = 'bank-statement-line-actions';
                const matchButton = document.createElement('button');
                matchButton.type = 'button';
                matchButton.className = 'button button-small';
                matchButton.textContent = 'Find Payment';
                matchButton.addEventListener('click', () => openBankStatementMatch(Number(statement.id), line));
                const categorizeButton = document.createElement('button');
                categorizeButton.type = 'button';
                categorizeButton.className = 'button button-small';
                categorizeButton.textContent = 'Categorize';
                categorizeButton.addEventListener('click', () => openBankStatementCategorize(Number(statement.id), line));
                actions.append(matchButton, categorizeButton);
                action.append(actions);
            } else {
                action.textContent = 'No access to post';
            }
        }
        if (!detail.lines.length) {
            const row = body.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 8;
            cell.className = 'empty-cell';
            cell.textContent = state.bankStatementLineTotal ? 'No statement lines were loaded.' : 'This statement has no transaction lines.';
        }
        const firstLine = detail.lines.length ? Number(detail.startOffset || 0) + 1 : 0;
        const lastLine = Number(detail.startOffset || 0) + detail.lines.length;
        $('#bank-statement-line-count').textContent = detail.lines.length
            ? `Showing ${firstLine}–${lastLine} of ${state.bankStatementLineTotal} statement lines`
            : `0 of ${state.bankStatementLineTotal} statement lines`;
        const more = $('#bank-statement-lines-more');
        more.hidden = Number(detail.nextOffset || 0) >= state.bankStatementLineTotal;
        more.disabled = false;
        more.textContent = `Load more (${Math.max(0, state.bankStatementLineTotal - Number(detail.nextOffset || 0))} remaining)`;
        focusRow?.scrollIntoView({ block: 'center' });
    }

    async function openBankStatementMatch(statementId,line) {
        state.currentStatementLine={statementId,line};state.bankStatementCandidates=[];setFormError('bank-statement-match-error');$('#bank-statement-match-line').textContent=`${line.transaction_date} · ${line.reference} · ${line.description||'—'} · ${formatAccountingAmount(line.amount,line.currency_code)}`;$('#bank-statement-candidate-rows').innerHTML='<tr><td colspan="7" class="empty-cell">Finding exact payment candidates…</td></tr>';$('#bank-statement-match-dialog').showModal();
        try{const result=await api(`/api/v1/accounting/bank-statements/${statementId}/lines/${line.id}/candidates`);state.bankStatementCandidates=result.candidates||[];renderBankStatementCandidates();}
        catch(error){setFormError('bank-statement-match-error',error.message);$('#bank-statement-candidate-rows').replaceChildren();}
    }

    function renderBankStatementCandidates() {
        const body=$('#bank-statement-candidate-rows');body.replaceChildren();if(!state.bankStatementCandidates.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=7;cell.className='empty-cell';cell.textContent='No exact customer receipt, customer refund, vendor payment, or vendor refund candidates. This line remains unmatched.';return;}
        for(const candidate of state.bankStatementCandidates){const row=body.insertRow();row.insertCell().textContent=candidate.payment_type==='customer'?'Customer receipt':candidate.payment_type==='customer_refund'?'Customer refund':candidate.payment_type==='vendor_refund'?'Vendor refund':'Vendor payment';row.insertCell().textContent=`${candidate.payment_name} · ${candidate.reference||'No reference'}`;row.insertCell().textContent=candidate.partner_name||'—';row.insertCell().textContent=candidate.document_name||'—';row.insertCell().textContent=candidate.payment_date;row.insertCell().textContent=formatAccountingAmount(candidate.amount,state.currentStatementLine?.line?.currency_code);const action=row.insertCell();const button=document.createElement('button');button.type='button';button.className='button button-small button-primary';button.textContent='Match';button.addEventListener('click',()=>reconcileBankStatementCandidate(candidate,button));action.append(button);}
    }

    async function reconcileBankStatementCandidate(candidate,button) {
        const current=state.currentStatementLine;if(!current)return;button.disabled=true;setFormError('bank-statement-match-error');const endpoint=candidate.payment_type==='customer'?`/api/v1/invoicing/payments/${candidate.id}/reconcile`:candidate.payment_type==='customer_refund'?`/api/v1/invoicing/refunds/${candidate.id}/reconcile`:candidate.payment_type==='vendor_refund'?`/api/v1/purchase/refunds/${candidate.id}/reconcile`:`/api/v1/purchase/payments/${candidate.id}/reconcile`;
        try{await api(endpoint,{method:'POST',body:JSON.stringify({statement_line_id:current.line.id})});$('#bank-statement-match-dialog').close();await loadBankStatements(0,false);await openBankStatement(current.statementId);}
        catch(error){setFormError('bank-statement-match-error',error.message);button.disabled=false;}
    }

    function openBankStatementCategorize(statementId,line) {
        if(!can('accounting.reconcile')||!can('accounting.post'))return;state.currentStatementLine={statementId,line};const form=$('#bank-statement-categorize-form');form.querySelector('button[type="submit"]').disabled=false;setFormError('bank-statement-categorize-error');$('#bank-statement-categorize-line').textContent=`${line.transaction_date} · ${line.reference} · ${line.description||'—'} · ${formatAccountingAmount(line.amount,line.currency_code)}`;form.elements.label.value=line.description||line.reference||'';$('#bank-statement-categorize-dialog').showModal();
    }

    async function categorizeBankStatementLine(event) {
        event.preventDefault();const current=state.currentStatementLine;const form=event.currentTarget;if(!current||!can('accounting.reconcile')||!can('accounting.post')||!form.reportValidity())return;setFormError('bank-statement-categorize-error');const button=form.querySelector('button[type="submit"]');button.disabled=true;
        try{await api(`/api/v1/accounting/bank-statements/${current.statementId}/lines/${current.line.id}/categorize`,{method:'POST',body:JSON.stringify({account_id:Number(form.elements.account_id.value),label:form.elements.label.value.trim()})});$('#bank-statement-categorize-dialog').close();await loadBankStatements(0,false);await openBankStatement(current.statementId);}
        catch(error){setFormError('bank-statement-categorize-error',error.message);button.disabled=false;}
    }

    async function importBankStatement(event) {
        event.preventDefault();const form=event.currentTarget;const companyId=$('#accounting-company').value;if(!companyId){showMessage($('#bank-statement-message'),'Select a company before importing a statement.',true);return;}if(!can('accounting.reconcile')){showMessage($('#bank-statement-message'),'You need Accounting Reconciliation access to import a statement.',true);return;}if(!form.reportValidity())return;
        const button=$('#bank-statement-import');button.disabled=true;showMessage($('#bank-statement-message'),'Importing and validating statement…');const data=new FormData(form);data.set('company_id',companyId);
        try{const result=await api('/api/v1/accounting/bank-statements/import',{method:'POST',body:data});showMessage($('#bank-statement-message'),result.duplicate?'This CSV was already imported for this journal.':'Statement imported. Review each line, match a payment, or categorize it to an account.');form.elements.opening_balance.value='';form.elements.closing_balance.value='';form.elements.statement_file.value='';await loadBankStatements(0,false);if(result.statement?.id)await openBankStatement(Number(result.statement.id));}
        catch(error){showMessage($('#bank-statement-message'),error.message,true);}
        finally{button.disabled=!can('accounting.reconcile');}
    }

    async function loadManualJournalEntries(offset=0,append=false) {
        if(!can('accounting.read'))return;const companyId=$('#accounting-company').value;if(!companyId){state.manualEntries=[];state.manualEntryTotal=0;renderManualJournalEntries();return;}
        const query=new URLSearchParams({company_id:companyId,state:$('#accounting-entry-state').value,q:$('#accounting-entry-search').value.trim(),limit:'50',offset:String(offset)});
        if(!append){showMessage($('#accounting-entry-message'));$('#accounting-entry-rows').innerHTML='<tr><td colspan="8" class="empty-cell">Loading journal entries…</td></tr>';}
        const result=await api(`/api/v1/accounting/manual-entries?${query}`);state.manualEntries=append?[...state.manualEntries,...(result.records||[])]:result.records||[];state.manualEntryTotal=Number(result.total||0);renderManualJournalEntries();
    }

    function renderManualJournalEntries() {
        const body=$('#accounting-entry-rows');body.replaceChildren();
        if(!state.manualEntries.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=8;cell.className='empty-cell';cell.textContent='No manual journal entries match this company and filter.';}
        for(const entry of state.manualEntries){const row=body.insertRow();const name=row.insertCell();const open=document.createElement('button');open.type='button';open.className='contact-link';open.textContent=entry.name;open.addEventListener('click',()=>openManualJournalEntry(Number(entry.id)));name.append(open);row.insertCell().textContent=entry.accounting_date;row.insertCell().textContent=`${entry.journal_code} · ${entry.journal_name}`;row.insertCell().textContent=entry.reference||'—';const stateCell=row.insertCell();const pill=document.createElement('span');pill.className=`status-pill ${entry.state==='posted'?'done':''}`;pill.textContent=entry.state==='posted'?'Posted':'Draft';stateCell.append(pill);row.insertCell().textContent=formatCurrencyAmount(entry.total_debit,entry.currency_code);row.insertCell().textContent=formatCurrencyAmount(entry.total_credit,entry.currency_code);const action=row.insertCell();const button=document.createElement('button');button.type='button';button.className='button button-small';button.textContent='Open';button.addEventListener('click',()=>openManualJournalEntry(Number(entry.id)));action.append(button);}
        $('#accounting-entry-count').textContent=`${state.manualEntries.length} of ${state.manualEntryTotal} entr${state.manualEntryTotal===1?'y':'ies'}`;const more=$('#accounting-entry-more');more.hidden=state.manualEntries.length>=state.manualEntryTotal;more.disabled=false;more.textContent=`Load more (${state.manualEntryTotal-state.manualEntries.length} remaining)`;
    }

    function addManualJournalLine(line={}) {
        const body=$('#accounting-entry-lines');const row=body.insertRow();row.className='accounting-entry-line';
        const accountCell=row.insertCell();const account=document.createElement('select');account.className='accounting-line-account';account.required=true;fillSelect(account,state.manualEntryOptions.accounts||[],{placeholder:'Select account',keepValue:line.account_id||'',label:(item)=>`${item.code} · ${item.name}`});accountCell.append(account);
        const labelCell=row.insertCell();const label=document.createElement('input');label.className='accounting-line-label';label.maxLength=190;label.value=line.label||'';label.placeholder='Account line label';labelCell.append(label);
        const contactCell=row.insertCell();const contact=document.createElement('select');contact.className='accounting-line-contact';fillSelect(contact,state.manualEntryOptions.contacts||[],{placeholder:'No Contact',keepValue:line.contact_id||'',label:(item)=>item.name});contactCell.append(contact);
        for(const [key,caption] of [['debit','Debit'],['credit','Credit']]){const cell=row.insertCell();const amount=document.createElement('input');amount.className=`accounting-line-${key}`;amount.type='number';amount.min='0';amount.step='any';amount.inputMode='decimal';amount.value=line[key]??'0';amount.setAttribute('aria-label',caption);amount.addEventListener('input',renderManualEntryTotals);cell.append(amount);}
        const action=row.insertCell();const remove=document.createElement('button');remove.type='button';remove.className='button button-small move-line-remove';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();renderManualEntryTotals();});action.append(remove);renderManualEntryTotals();
    }

    function renderManualEntryTotals() {
        const currency=state.manualEntryOptions.company?.currency_code||'—';let debit=0,credit=0;for(const row of $$('.accounting-entry-line',$('#accounting-entry-lines'))){debit+=Number(row.querySelector('.accounting-line-debit').value||0);credit+=Number(row.querySelector('.accounting-line-credit').value||0);}
        $('#accounting-entry-debit-total').textContent=formatCurrencyAmount(debit,currency);$('#accounting-entry-credit-total').textContent=formatCurrencyAmount(credit,currency);
    }

    function setManualEntryEditable(editable) {
        const form=$('#accounting-entry-form');for(const control of $$('input,select,textarea',form))control.disabled=!editable;
        $('#accounting-entry-add-line').disabled=!editable;
        for(const button of $$('.accounting-entry-line button',form))button.disabled=!editable;
    }

    async function openManualJournalEntry(id=null) {
        if(!can('accounting.read'))return;const form=$('#accounting-entry-form');form.reset();form.dataset.id='';state.currentManualEntry=null;setFormError('accounting-entry-form-error');$('#accounting-entry-save-state').textContent='';$('#accounting-entry-lines').replaceChildren();
        const companyId=String($('#accounting-company').value||state.defaultCompanyId||'');if(!companyId){setAccountingView('entries');showMessage($('#accounting-entry-message'),'Select a company before creating a journal entry.',true);return;}
        if(String(state.manualEntryOptions.company?.id||'')!==companyId)await initializeAccounting(companyId);
        if(!id&&!(state.manualEntryOptions.journals||[]).length){setAccountingView('entries');showMessage($('#accounting-entry-message'),'No active General Journal is set up for this company. Create one in Accounting → Configuration, then try again.',true);return;}
        fillSelect($('#accounting-entry-company'),state.companies,{placeholder:'Select company',keepValue:companyId});$('#accounting-entry-company').value=companyId;form.elements.accounting_date.value=accountingToday();
        if(id){try{const entry=(await api(`/api/v1/accounting/manual-entries/${id}`)).entry;if(String(entry.company_id)!==String($('#accounting-company').value)){await initializeAccounting(String(entry.company_id));}state.currentManualEntry=entry;form.dataset.id=String(entry.id);$('#accounting-entry-dialog-title').textContent=`${entry.name} · ${entry.state==='posted'?'Posted':'Draft'}`;$('#accounting-entry-company').value=String(entry.company_id);$('#accounting-entry-journal').value=String(entry.journal_id);form.elements.accounting_date.value=entry.accounting_date;form.elements.reference.value=entry.reference||'';form.elements.narration.value=entry.narration||'';for(const line of entry.lines||[])addManualJournalLine(line);}
            catch(error){showMessage($('#accounting-entry-message'),error.message,true);return;}}
        else {$('#accounting-entry-dialog-title').textContent='New Journal Entry';$('#accounting-entry-journal').value=String(state.manualEntryOptions.journals?.[0]?.id||'');addManualJournalLine();addManualJournalLine();}
        const isDraft=!state.currentManualEntry||state.currentManualEntry.state==='draft';setManualEntryEditable(isDraft&&can('accounting.write'));$('#accounting-entry-company').disabled=Boolean(state.currentManualEntry);
        $('#accounting-entry-save').hidden=!isDraft||!can('accounting.write');$('#accounting-entry-discard').hidden=!state.currentManualEntry||!isDraft||!can('accounting.write');$('#accounting-entry-post').hidden=!state.currentManualEntry||!isDraft||!can('accounting.post');$('#accounting-entry-reverse').hidden=!state.currentManualEntry||state.currentManualEntry.state!=='posted'||state.currentManualEntry.reversal_of_entry_id!==null||state.currentManualEntry.has_reversal||!can('accounting.post');
        $('#accounting-entry-currency-note').textContent=`All lines use ${state.manualEntryOptions.company?.currency_code||''} company currency. Draft totals may be unbalanced; posting requires exact balance.`;renderManualEntryTotals();$('#accounting-entry-dialog').showModal();
    }

    function manualEntryPayload() {
        const form=$('#accounting-entry-form');return{company_id:Number(form.elements.company_id.value),journal_id:Number(form.elements.journal_id.value),accounting_date:form.elements.accounting_date.value,reference:form.elements.reference.value.trim(),narration:form.elements.narration.value.trim(),lines:$$('.accounting-entry-line',$('#accounting-entry-lines')).map((row)=>({account_id:Number(row.querySelector('.accounting-line-account').value||0),contact_id:row.querySelector('.accounting-line-contact').value?Number(row.querySelector('.accounting-line-contact').value):null,label:row.querySelector('.accounting-line-label').value.trim(),debit:row.querySelector('.accounting-line-debit').value||'0',credit:row.querySelector('.accounting-line-credit').value||'0'}))};
    }

    async function saveManualJournalEntry(event) {
        event.preventDefault();const form=$('#accounting-entry-form');if(!can('accounting.write')||!form.reportValidity())return;setFormError('accounting-entry-form-error');$('#accounting-entry-save-state').textContent='Saving…';
        try{const id=form.dataset.id||'';const result=await api(id?`/api/v1/accounting/manual-entries/${id}`:'/api/v1/accounting/manual-entries',{method:id?'PATCH':'POST',body:JSON.stringify(manualEntryPayload())});state.currentManualEntry=result.entry;form.dataset.id=String(result.entry.id);$('#accounting-entry-company').disabled=true;$('#accounting-entry-dialog-title').textContent=`${result.entry.name} · Draft`;$('#accounting-entry-save-state').textContent='Draft saved';$('#accounting-entry-post').hidden=!can('accounting.post');$('#accounting-entry-discard').hidden=!can('accounting.write');await loadManualJournalEntries(0,false);}
        catch(error){$('#accounting-entry-save-state').textContent='';setFormError('accounting-entry-form-error',error.message);}
    }

    async function postManualJournalEntry() {
        const entry=state.currentManualEntry;if(!entry||entry.state!=='draft'||!can('accounting.post'))return;setFormError('accounting-entry-form-error');
        try{const result=await api(`/api/v1/accounting/manual-entries/${entry.id}/post`,{method:'POST',body:JSON.stringify({})});state.currentManualEntry=result.entry;$('#accounting-entry-dialog').close();await loadManualJournalEntries(0,false);if(state.accountingView==='reports')await loadAccountingReport(0,false);}
        catch(error){setFormError('accounting-entry-form-error',error.message);}
    }

    async function discardManualJournalEntry() {
        const entry=state.currentManualEntry;if(!entry||entry.state!=='draft'||!can('accounting.write'))return;if(!window.confirm(`Discard draft ${entry.name}? This removes the draft and its lines.`))return;
        try{await api(`/api/v1/accounting/manual-entries/${entry.id}`,{method:'DELETE'});$('#accounting-entry-dialog').close();await loadManualJournalEntries(0,false);}
        catch(error){setFormError('accounting-entry-form-error',error.message);}
    }

    function openAccountingReversal() {
        const entry=state.currentManualEntry;if(!entry||entry.state!=='posted'||!can('accounting.post'))return;const form=$('#accounting-reversal-form');form.reset();setFormError('accounting-reversal-error');form.elements.reversal_date.value=accountingToday();form.elements.reference.value=`Reversal of ${entry.name}`.slice(0,120);$('#accounting-reversal-source').textContent=`Original entry: ${entry.name} · ${entry.accounting_date} · ${entry.journal_name}`;$('#accounting-reversal-dialog').showModal();
    }

    async function reverseManualJournalEntry(event) {
        event.preventDefault();const entry=state.currentManualEntry;const form=$('#accounting-reversal-form');if(!entry||!can('accounting.post')||!form.reportValidity())return;
        try{const result=await api(`/api/v1/accounting/manual-entries/${entry.id}/reverse`,{method:'POST',body:JSON.stringify({reversal_date:form.elements.reversal_date.value,reference:form.elements.reference.value.trim()})});$('#accounting-reversal-dialog').close();$('#accounting-entry-dialog').close();await loadManualJournalEntries(0,false);if(state.accountingView==='reports')await loadAccountingReport(0,false);await openManualJournalEntry(Number(result.entry.id));}
        catch(error){setFormError('accounting-reversal-error',error.message);}
    }

    async function createInvoiceFromSales() {
        const order=state.currentSalesOrder;if(!order||!can('invoicing.read')||!can('invoicing.write'))return;
        $('#sales-invoice').disabled=true;$('#sales-save-state').textContent='Creating invoice…';
        try{const invoice=(await api(`/api/v1/invoicing/from-sales/${order.id}`,{method:'POST',body:JSON.stringify({})})).invoice;$('#sales-dialog').close();selectPage('invoicing');$('#invoice-company').value=String(order.company_id);await initializeInvoicing(String(order.company_id));await openInvoice(Number(invoice.id));}
        catch(error){setFormError('sales-form-error',error.message);}
        finally{$('#sales-invoice').disabled=false;$('#sales-save-state').textContent='';}
    }

    async function saveAccountingAccount(event) {
        event.preventDefault();const form=$('#account-setup-form');if(!can('accounting.write')||!form.reportValidity())return;
        try{await api('/api/v1/invoicing/accounts',{method:'POST',body:JSON.stringify({company_id:Number(form.elements.company_id.value),code:form.elements.code.value.trim(),name:form.elements.name.value.trim(),account_type:form.elements.account_type.value,allow_reconciliation:form.elements.allow_reconciliation.checked})});form.elements.code.value='';form.elements.name.value='';form.elements.account_type.value='';form.elements.allow_reconciliation.checked=false;await initializeInvoicing($('#invoice-company').value);showMessage($('#invoicing-message'),'Account created. Assign it on the Contact, product, or tax before posting invoices.');}
        catch(error){showMessage($('#invoicing-message'),error.message,true);}
    }

    async function saveSalesJournal(event) {
        event.preventDefault();const form=$('#journal-setup-form');if(!can('accounting.write')||!form.reportValidity())return;
        const journalType=form.elements.journal_type.value;const payload={company_id:Number(form.elements.company_id.value),code:form.elements.code.value.trim(),name:form.elements.name.value.trim(),journal_type:journalType};
        if(['bank','cash'].includes(journalType)){payload.default_account_id=Number(form.elements.default_account_id.value);payload.outstanding_receipts_account_id=Number(form.elements.outstanding_receipts_account_id.value);if(form.elements.outstanding_payments_account_id.value)payload.outstanding_payments_account_id=Number(form.elements.outstanding_payments_account_id.value);}
        try{await api('/api/v1/invoicing/journals',{method:'POST',body:JSON.stringify(payload)});form.elements.code.value='';form.elements.name.value='';form.elements.default_account_id.value='';form.elements.outstanding_receipts_account_id.value='';form.elements.outstanding_payments_account_id.value='';await initializeInvoicing($('#invoice-company').value);showMessage($('#invoicing-message'),journalType==='sale'?'Sales Journal created for customer invoices.':journalType==='purchase'?'Purchase Journal created for vendor bills.':journalType==='general'?'General Journal created for manual accounting entries.':'Bank / Cash journal created for customer receipts and configured supplier payments.');}
        catch(error){showMessage($('#invoicing-message'),error.message,true);}
    }

    function salesFormPayload() {
        const form = $('#sales-form'); const payload = {};
        for (const field of ['company_id','partner_contact_id','contract_id','invoice_contact_id','delivery_contact_id','crm_lead_id','sale_order_template_id','salesperson_user_id','pricelist_id','payment_terms_id','warehouse_id','currency_code','order_date','validity_date','client_order_ref','note']) {
            const value = String(form.elements.namedItem(field).value || '').trim(); payload[field] = value || null;
        }
        for (const field of ['company_id','partner_contact_id','contract_id','invoice_contact_id','delivery_contact_id','crm_lead_id','sale_order_template_id','salesperson_user_id','pricelist_id','payment_terms_id','warehouse_id']) if (payload[field] !== null) payload[field] = Number(payload[field]);
        payload.lines = $$('.sales-line', $('#sales-lines')).map((row) => ({
            product_id: row.querySelector('[name="product_id"]').value ? Number(row.querySelector('[name="product_id"]').value) : null,
            uom_id: row.querySelector('[name="uom_id"]').value ? Number(row.querySelector('[name="uom_id"]').value) : null,
            description: row.querySelector('[name="description"]').value.trim(), quantity: row.querySelector('[name="quantity"]').value,
            unit_price: row.querySelector('[name="unit_price"]').value, discount_percent: row.querySelector('[name="discount_percent"]').value,
            tax_id: row.querySelector('[name="tax_id"]').value ? Number(row.querySelector('[name="tax_id"]').value) : null,
        }));
        return payload;
    }

    async function saveSalesOrder(event) {
        if (event) event.preventDefault(); const form = $('#sales-form'); if (!can('sales.write') || !form.reportValidity()) return;
        setFormError('sales-form-error'); $('#sales-save-state').textContent = 'Saving…';
        try {
            const id = form.dataset.id || ''; const result = await api(id ? `/api/v1/sales/${id}` : '/api/v1/sales', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(salesFormPayload()) });
            state.currentSalesOrder = result.order; await loadSalesRecords(); await openSalesOrder(Number(result.order.id)); showMessage($('#sales-message'), id ? 'Quotation updated.' : 'Quotation created.');
        } catch (error) { $('#sales-save-state').textContent = ''; setFormError('sales-form-error', error.message); }
    }

    async function updateSalesState(action) {
        const id = $('#sales-form').dataset.id; if (!id) return;
        const labels = { send: 'Mark this quotation as sent? No email will be sent from this action.', confirm: 'Confirm this order? Goods will create linked draft delivery moves in Inventory.', cancel: 'Cancel this quotation?' };
        if (!window.confirm(labels[action])) return;
        try {
            const result = await api(`/api/v1/sales/${id}/${action}`, { method: 'POST', body: JSON.stringify({}) });
            state.currentSalesOrder = result.order; await loadSalesRecords(); await openSalesOrder(Number(id));
            if (action === 'confirm' && can('crm.read')) await loadCrmRecords();
            const pending=result.order.approval?.status==='pending';
            showMessage($('#sales-message'), action === 'confirm'&&pending ? 'Approval requested. The order is still unconfirmed, so no Inventory delivery was created.' : action === 'confirm' ? 'Sales order confirmed; related goods deliveries are available in Inventory.' : action === 'send' ? 'Quotation marked as sent.' : 'Quotation cancelled.');
        } catch (error) { setFormError('sales-form-error', error.message); }
    }

    async function addCrmActivity() {
        const id = $('#crm-form').dataset.id; if (!id) return;
        const summary = $('#crm-activity-summary').value.trim();
        if (!summary) { $('#crm-activity-summary').focus(); return; }
        const dueAt = $('#crm-activity-due').value;
        const owner = $('#crm-activity-owner').value;
        try {
            const result = await api(`/api/v1/crm/leads/${id}/activities`, { method: 'POST', body: JSON.stringify({
                activity_type: $('#crm-activity-type').value, summary, due_at: dueAt || null, assigned_user_id: owner ? Number(owner) : null,
            }) });
            state.currentCrmLead = result.lead; renderCrmActivities(result.lead.activities || []);
            $('#crm-activity-summary').value = ''; $('#crm-activity-due').value = ''; showMessage($('#crm-message'), 'Activity scheduled.');
        } catch (error) { setFormError('crm-form-error', error.message); }
    }

    async function completeCrmActivity(id) {
        try {
            const result = await api(`/api/v1/crm/activities/${id}/done`, { method: 'POST', body: JSON.stringify({}) });
            state.currentCrmLead = result.lead; renderCrmActivities(result.lead.activities || []); showMessage($('#crm-message'), 'Activity marked done.');
        } catch (error) { setFormError('crm-form-error', error.message); }
    }

    async function initializeInventory() {
        const companySelect = $('#inventory-company');
        const current = companySelect.value;
        if (!can('inventory.read')) {
            if (can('products.create')) {
                selectPage('products');
                return;
            }
            throw new Error('You do not have Inventory read access.');
        }
        // A Dashboard Quick Action may select a valid company before Inventory
        // has loaded its own company list. Preserve that requested company and
        // let the company-scoped API authorize it instead of silently falling
        // back to the user's default company.
        const currentInventoryCompany = state.companies.some((company) => Number(company.id) === Number(current)) ? current : '';
        if (!$('#inventory-closing-date').value) $('#inventory-closing-date').value = accountingToday();
        await loadInventoryOptions(currentInventoryCompany);
        await loadProducts();
        selectInventoryView(state.inventoryView);
    }

    async function initializeProductCatalog() {
        state.inventoryView = 'products';
        state.products = []; state.totalProducts = 0;
        await loadProductCatalogOptions('');
        selectInventoryView('products');
    }

    function openInventoryAccountingSettings() {
        state.inventoryView = 'accounting-setup';
        if (state.page === 'inventory') selectInventoryView('accounting-setup');
        else selectPage('inventory');
    }

    function selectInventoryView(view) {
        if (view !== 'products' && !can('inventory.read')) return;
        state.inventoryView = view;
        const createOnlyProductPage = state.page === 'products' || !can('inventory.read');
        $('#products-view').hidden = view !== 'products' || createOnlyProductPage;
        $('#product-create-only').hidden = view !== 'products' || !createOnlyProductPage;
        $('#moves-view').hidden = view !== 'moves';
        $('#levels-view').hidden = view !== 'levels';
        $('#valuation-view').hidden = view !== 'valuation';
        $('#inventory-accounting-view').hidden = view !== 'accounting-setup';
        $('#cycle-counts-view').hidden = view !== 'cycle-counts';
        $('#warehouses-view').hidden = view !== 'warehouses';
        $('#inventory-master-data-view').hidden = view !== 'master-data';
        $('#product-search-wrap').hidden = view !== 'products' || createOnlyProductPage;
        $('#product-status').hidden = view !== 'products' || createOnlyProductPage;
        $('#export-products').hidden = view !== 'products' || createOnlyProductPage || !can('data_export.allowed');
        $('#import-products').hidden = view !== 'products' || !(can('products.create') || can('inventory.write'));
        $('#move-search-wrap').hidden = view !== 'moves';
        $('#move-status').hidden = view !== 'moves';
        $('#valuation-search-wrap').hidden = view !== 'valuation';
        $('#cycle-count-search-wrap').hidden = view !== 'cycle-counts';
        $('#cycle-count-status').hidden = view !== 'cycle-counts';
        $('#warehouse-search-wrap').hidden = view !== 'warehouses';
        $('#inventory-master-search-wrap').hidden = view !== 'master-data';
        $('#refresh-inventory').hidden = view === 'accounting-setup' || createOnlyProductPage;
        $('#inventory-note').hidden = view === 'accounting-setup' || view === 'warehouses' || view === 'master-data';
        $$('.inventory-view-button').forEach((button) => {
            button.classList.toggle('active', button.dataset.inventoryView === view);
            button.hidden = !can('inventory.read') && button.dataset.inventoryView !== 'products';
        });
        const onHandColumn = $('#products-view thead th:nth-child(7)');
        if (onHandColumn) onHandColumn.hidden = !can('inventory.read');
        const actions = $('#page-actions'); actions.replaceChildren();
        if (view === 'products' && can('products.create') && state.inventoryOptions.product_create_access) {
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Product';
            add.addEventListener('click', () => openProduct()); actions.append(add);
        } else if (view === 'moves' && can('inventory.write')) {
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Stock Move';
            add.addEventListener('click', () => openStockMove()); actions.append(add);
        } else if (view === 'cycle-counts' && can('inventory.write')) {
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Cycle Count';
            add.addEventListener('click', () => openInventoryCycleCountCreate().catch((error) => showMessage($('#inventory-message'), error.message, true))); actions.append(add);
        } else if (view === 'accounting-setup' && can('accounting.write')) {
            const save = document.createElement('button'); save.className = 'button button-primary'; save.type = 'button'; save.textContent = 'Save Settings';
            save.addEventListener('click', saveInventoryAccounting); actions.append(save);
        } else if (view === 'warehouses' && can('inventory.write')) {
            const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button'; add.textContent = 'New Warehouse';
            add.addEventListener('click', openWarehouseCreate); actions.append(add);
            const addLocation = document.createElement('button'); addLocation.className = 'button'; addLocation.type = 'button'; addLocation.textContent = 'New Location';
            addLocation.addEventListener('click', openLocationCreate); actions.append(addLocation);
        }
        if (view === 'moves') loadStockMoves().catch((error) => showMessage($('#inventory-message'), error.message, true));
        if (view === 'levels') loadStockLevels().catch((error) => showMessage($('#inventory-message'), error.message, true));
        if (view === 'cycle-counts') loadInventoryCycleCountOptions($('#inventory-company').value).then(loadInventoryCycleCounts).catch((error) => showMessage($('#inventory-message'), error.message, true));
        if (view === 'valuation') loadInventoryValuation().catch((error) => showMessage($('#inventory-message'), error.message, true));
        if (view === 'accounting-setup') loadInventoryAccounting().catch((error) => showMessage($('#inventory-message'), error.message, true));
        if (view === 'warehouses') renderInventoryWarehouses();
        if (view === 'master-data') renderInventoryMasterData();
    }

    async function loadInventoryOptions(companyId) {
        const query = companyId ? '?company_id=' + encodeURIComponent(companyId) : '';
        const options = (await api('/api/v1/inventory/options' + query)).options;
        state.inventoryOptions = options;
        state.inventoryCompanies = options.companies || [];
        setHeader();
        if (!options.company_id) { $('#inventory-company').value = ''; $('#product-company').value = ''; }
        fillSelect($('#inventory-company'), state.inventoryCompanies, { placeholder: 'Select company', keepValue: options.company_id || '' });
        fillSelect($('#product-company'), state.inventoryCompanies, { placeholder: 'Select company', keepValue: options.company_id || '' });
        fillProductCatalogSelects(options);
        fillSelect($('#inventory-category-parent'), options.categories || [], { placeholder: 'No Parent Category' });
        fillSelect($('#inventory-uom-category'), options.uom_categories || [], { placeholder: 'Select unit category' });
        fillSelect($('#move-company'), state.inventoryCompanies, { placeholder: 'Select company', keepValue: companyId || options.company_id || '' });
        fillSelect($('#move-uom'), options.units || [], { placeholder: 'Select unit', label: unitLabel });
        const usableLocations = (options.locations || []).filter((row) => row.location_usage !== 'view');
        const locationLabel = (row) => `${row.complete_name} · ${row.location_usage}`;
        fillSelect($('#move-source'), usableLocations, { placeholder: 'Select source', label: locationLabel });
        fillSelect($('#move-destination'), usableLocations, { placeholder: 'Select destination', label: locationLabel });
        fillSelect($('#warehouse-partner'), options.contacts || [], { placeholder: 'No linked partner', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
    }

    function fillProductCatalogSelects(options) {
        fillSelect($('#product-category'), options.categories || [], { placeholder: 'Select category' });
        const unitLabel = (row) => `${row.name} (${row.abbreviation}) · ${row.category_name}`;
        fillSelect($('#product-uom'), options.units || [], { placeholder: 'Select sales unit', label: unitLabel });
        fillSelect($('#product-purchase-uom'), options.units || [], { placeholder: 'Select purchase unit', label: unitLabel });
        fillSelect($('#product-income-account'), options.income_accounts || [], { placeholder: 'Select income account', label: (row) => `${row.code} · ${row.name}` });
        fillSelect($('#product-purchase-account'), options.purchase_accounts || [], { placeholder: 'Select account before vendor bill posting', label: (row) => `${row.code} · ${row.name} · ${row.account_type}` });
        const accountingAccess = options.accounting_access !== false;
        $('#product-income-account').disabled = !accountingAccess;
        $('#product-purchase-account').disabled = !accountingAccess;
    }

    async function loadProductCatalogOptions(companyId) {
        const query = companyId ? '?company_id=' + encodeURIComponent(companyId) : '';
        const options = (await api('/api/v1/products/options' + query)).options;
        state.inventoryOptions = options;
        state.productCompanies = options.companies || [];
        setHeader();
        if (!options.company_id) { $('#inventory-company').value = ''; $('#product-company').value = ''; }
        fillSelect($('#inventory-company'), state.productCompanies, { placeholder: 'Select company', keepValue: options.company_id || '' });
        fillSelect($('#product-company'), state.productCompanies, { placeholder: 'Select company', keepValue: options.company_id || '' });
        fillProductCatalogSelects(options);
    }

    function renderInventoryMasterData() {
        const options = state.inventoryOptions || {};
        const query = $('#inventory-master-search').value.trim().toLocaleLowerCase();
        const categories = options.category_records || options.categories || [];
        const unitCategories = options.uom_category_records || options.uom_categories || [];
        const units = options.unit_records || options.units || [];
        const categoryById = new Map(categories.map((row) => [Number(row.id), row]));
        const uomCategoryById = new Map(unitCategories.map((row) => [Number(row.id), row]));
        const matches = (...values) => !query || values.filter((value) => value !== null && value !== undefined).join(' ').toLocaleLowerCase().includes(query);
        const categoryBody = $('#inventory-master-category-rows'); categoryBody.replaceChildren();
        const filteredCategories = categories.filter((row) => matches(row.name, row.parent_category_name || categoryById.get(Number(row.parent_category_id))?.name, row.costing_method, row.inventory_valuation, Number(row.active) ? 'active' : 'archived'));
        if (!filteredCategories.length) { const row = categoryBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 7; cell.className = 'empty-cell'; cell.textContent = query ? 'No product categories match this search.' : 'No product categories are available for this company.'; }
        for (const category of filteredCategories) {
            const row = categoryBody.insertRow();
            row.insertCell().textContent = category.name;
            row.insertCell().textContent = category.parent_category_name || categoryById.get(Number(category.parent_category_id))?.name || '—';
            row.insertCell().textContent = ({standard:'Standard Price',fifo:'FIFO',average:'Average Cost'})[category.costing_method] || category.costing_method;
            row.insertCell().textContent = category.inventory_valuation === 'real_time' ? 'Automated' : 'Manual';
            row.insertCell().textContent = category.company_id === null ? 'Shared' : 'Company-specific';
            row.insertCell().textContent = Number(category.active) ? 'Active' : 'Archived';
            renderInventoryMasterActions(row.insertCell(), category, () => openInventoryCategoryEdit(category), 'product-categories');
        }
        const unitCategoryBody = $('#inventory-master-uom-category-rows'); unitCategoryBody.replaceChildren();
        const filteredUnitCategories = unitCategories.filter((row) => matches(row.name, row.company_id === null ? 'shared' : 'company-specific', Number(row.active) ? 'active' : 'archived'));
        if (!filteredUnitCategories.length) { const row = unitCategoryBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 4; cell.className = 'empty-cell'; cell.textContent = query ? 'No unit categories match this search.' : 'No unit categories are available for this company.'; }
        for (const category of filteredUnitCategories) {
            const row = unitCategoryBody.insertRow();
            row.insertCell().textContent = category.name;
            row.insertCell().textContent = category.company_id === null ? 'Shared' : 'Company-specific';
            row.insertCell().textContent = Number(category.active) ? 'Active' : 'Archived';
            renderInventoryMasterActions(row.insertCell(), category, () => openInventoryUomCategoryEdit(category), 'uom-categories');
        }
        const unitBody = $('#inventory-master-uom-rows'); unitBody.replaceChildren();
        const filteredUnits = units.filter((unit) => matches(unit.name, unit.abbreviation, uomCategoryById.get(Number(unit.category_id))?.name || unit.category_name, unit.unit_type, unit.factor_to_reference, unit.rounding, Number(unit.active) ? 'active' : 'archived'));
        if (!filteredUnits.length) { const row = unitBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 9; cell.className = 'empty-cell'; cell.textContent = query ? 'No units match this search.' : 'No units of measure are available for this company.'; }
        for (const unit of filteredUnits) {
            const row = unitBody.insertRow();
            row.insertCell().textContent = unit.name;
            row.insertCell().textContent = unit.abbreviation;
            row.insertCell().textContent = uomCategoryById.get(Number(unit.category_id))?.name || unit.category_name || '—';
            row.insertCell().textContent = ({reference:'Reference',bigger:'Bigger',smaller:'Smaller'})[unit.unit_type] || unit.unit_type;
            row.insertCell().textContent = String(unit.factor_to_reference);
            row.insertCell().textContent = String(unit.rounding);
            row.insertCell().textContent = unit.company_id === null ? 'Shared' : 'Company-specific';
            row.insertCell().textContent = Number(unit.active) ? 'Active' : 'Archived';
            renderInventoryMasterActions(row.insertCell(), unit, () => openInventoryUomEdit(unit), 'uoms');
        }
        $('#inventory-master-category-total').textContent = `${filteredCategories.length} categor${filteredCategories.length === 1 ? 'y' : 'ies'}`;
        $('#inventory-master-uom-category-total').textContent = `${filteredUnitCategories.length} unit categor${filteredUnitCategories.length === 1 ? 'y' : 'ies'}`;
        $('#inventory-master-uom-total').textContent = `${filteredUnits.length} unit${filteredUnits.length === 1 ? '' : 's'}`;
        for (const id of ['inventory-new-category', 'inventory-new-uom-category', 'inventory-new-uom']) $( '#' + id).hidden = !can('inventory.write');
    }

    function renderInventoryMasterActions(cell, record, edit, resource) {
        if (record.company_id === null) { cell.textContent = 'Shared record · read-only'; return; }
        if (!can('inventory.write')) { cell.textContent = '—'; return; }
        const editButton = document.createElement('button'); editButton.type = 'button'; editButton.className = 'button button-small'; editButton.textContent = 'Edit'; editButton.addEventListener('click', edit); cell.append(editButton);
        const active = Number(record.active) === 1;
        const statusButton = document.createElement('button'); statusButton.type = 'button'; statusButton.className = 'button button-small'; statusButton.textContent = active ? 'Archive' : 'Restore'; statusButton.addEventListener('click', () => setInventoryMasterStatus(resource, record, !active)); cell.append(statusButton);
    }

    async function setInventoryMasterStatus(resource, record, active) {
        const action = active ? 'restore' : 'archive';
        if (!window.confirm(`${active ? 'Restore' : 'Archive'} “${record.name}”? ${active ? 'It will be available in active selectors again.' : 'It will be hidden from new transaction selectors.'}`)) return;
        const companyId = Number($('#inventory-company').value);
        try {
            await api(`/api/v1/inventory/${resource}/${Number(record.id)}/status`, { method: 'PATCH', body: JSON.stringify({ company_id: companyId, active }) });
            await loadInventoryOptions(String(companyId)); renderInventoryMasterData();
            showMessage($('#inventory-message'), `${record.name} ${action}d.`);
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    function inventoryMasterCompanyContext(targetId) {
        const companyId = Number($('#inventory-company').value);
        const company = (state.companies || []).find((row) => Number(row.id) === companyId);
        if (!companyId || !company) { showMessage($('#inventory-message'), 'Choose an allowed company before creating Inventory master data.', true); return false; }
        $(targetId).textContent = `Company-specific record for ${company.name}.`;
        return true;
    }

    function openInventoryCategoryCreate() {
        if (!inventoryMasterCompanyContext('#inventory-category-company-context')) return;
        const form = $('#inventory-category-form'); form.reset(); form.dataset.id = '';
        fillSelect($('#inventory-category-parent'), state.inventoryOptions?.categories || [], { placeholder: 'No Parent Category' });
        $('#inventory-category-dialog-title').textContent = 'New Product Category'; $('#inventory-category-submit').textContent = 'Create Category';
        setFormError('inventory-category-form-error', '');
        $('#inventory-category-dialog').showModal();
    }

    function openInventoryCategoryEdit(category) {
        if (!inventoryMasterCompanyContext('#inventory-category-company-context')) return;
        const form = $('#inventory-category-form'); form.reset(); form.dataset.id = String(category.id);
        const parents = (state.inventoryOptions?.category_records || []).filter((row) => Number(row.id) !== Number(category.id) && Number(row.active));
        fillSelect($('#inventory-category-parent'), parents, { placeholder: 'No Parent Category', keepValue: category.parent_category_id || '' });
        form.elements.name.value = category.name; form.elements.costing_method.value = category.costing_method; form.elements.inventory_valuation.value = category.inventory_valuation; form.elements.parent_category_id.value = category.parent_category_id || '';
        $('#inventory-category-dialog-title').textContent = 'Edit Product Category'; $('#inventory-category-submit').textContent = 'Save';
        setFormError('inventory-category-form-error', ''); $('#inventory-category-dialog').showModal();
    }

    function openInventoryUomCategoryCreate() {
        if (!inventoryMasterCompanyContext('#inventory-uom-category-company-context')) return;
        const form = $('#inventory-uom-category-form'); form.reset(); form.dataset.id = '';
        $('#inventory-uom-category-dialog-title').textContent = 'New Unit Category'; $('#inventory-uom-category-submit').textContent = 'Create Unit Category';
        setFormError('inventory-uom-category-form-error', '');
        $('#inventory-uom-category-dialog').showModal();
    }

    function openInventoryUomCategoryEdit(category) {
        if (!inventoryMasterCompanyContext('#inventory-uom-category-company-context')) return;
        const form = $('#inventory-uom-category-form'); form.reset(); form.dataset.id = String(category.id); form.elements.name.value = category.name;
        $('#inventory-uom-category-dialog-title').textContent = 'Edit Unit Category'; $('#inventory-uom-category-submit').textContent = 'Save';
        setFormError('inventory-uom-category-form-error', ''); $('#inventory-uom-category-dialog').showModal();
    }

    function openInventoryUomCreate() {
        if (!inventoryMasterCompanyContext('#inventory-uom-company-context')) return;
        const form = $('#inventory-uom-form'); form.reset(); form.dataset.id = '';
        fillSelect($('#inventory-uom-category'), state.inventoryOptions?.uom_categories || [], { placeholder: 'Select unit category' });
        $('#inventory-uom-type').value = 'reference';
        $('#inventory-uom-factor').value = '1';
        syncInventoryUomFactor();
        $('#inventory-uom-dialog-title').textContent = 'New Unit of Measure'; $('#inventory-uom-submit').textContent = 'Create Unit';
        setFormError('inventory-uom-form-error', '');
        $('#inventory-uom-dialog').showModal();
    }

    function openInventoryUomEdit(unit) {
        if (!inventoryMasterCompanyContext('#inventory-uom-company-context')) return;
        const form = $('#inventory-uom-form'); form.reset(); form.dataset.id = String(unit.id);
        fillSelect($('#inventory-uom-category'), state.inventoryOptions?.uom_category_records?.filter((row) => Number(row.active)) || state.inventoryOptions?.uom_categories || [], { placeholder: 'Select unit category', keepValue: unit.category_id });
        form.elements.name.value = unit.name; form.elements.abbreviation.value = unit.abbreviation; form.elements.unit_type.value = unit.unit_type;
        form.elements.factor_to_reference.value = unit.factor_to_reference; form.elements.rounding.value = unit.rounding; form.elements.category_id.value = String(unit.category_id);
        syncInventoryUomFactor(); $('#inventory-uom-dialog-title').textContent = 'Edit Unit of Measure'; $('#inventory-uom-submit').textContent = 'Save';
        setFormError('inventory-uom-form-error', ''); $('#inventory-uom-dialog').showModal();
    }

    function syncInventoryUomFactor() {
        const type = $('#inventory-uom-type').value;
        const factor = $('#inventory-uom-factor');
        if (type === 'reference') { factor.min = '1'; factor.max = '1'; factor.value = '1'; $('#inventory-uom-factor-help').textContent = 'Reference unit factor must equal 1. This category can have only one active Reference unit.'; }
        else if (type === 'bigger') { factor.min = '1.0000000001'; factor.removeAttribute('max'); if (Number(factor.value) <= 1) factor.value = '12'; $('#inventory-uom-factor-help').textContent = 'Enter how many Reference units make one of this Bigger unit (for example, 12 for a dozen).'; }
        else { factor.min = '0.0000000001'; factor.max = '0.9999999999'; if (Number(factor.value) >= 1) factor.value = '0.001'; $('#inventory-uom-factor-help').textContent = 'Enter the fraction of one Reference unit represented by this Smaller unit (for example, 0.001 for a gram when the reference is a kilogram).'; }
    }

    async function saveInventoryMasterForm(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const companyId = Number($('#inventory-company').value);
        const data = Object.fromEntries(new FormData(form).entries());
        data.company_id = companyId;
        if ('parent_category_id' in data) data.parent_category_id = data.parent_category_id ? Number(data.parent_category_id) : null;
        if ('category_id' in data) data.category_id = Number(data.category_id);
        const config = {
            'inventory-category-form': ['/api/v1/inventory/product-categories', 'inventory-category-dialog', 'inventory-category-form-error', 'Product category saved; it is available to Products and valuation setup.'],
            'inventory-uom-category-form': ['/api/v1/inventory/uom-categories', 'inventory-uom-category-dialog', 'inventory-uom-category-form-error', 'Unit category saved.'],
            'inventory-uom-form': ['/api/v1/inventory/uoms', 'inventory-uom-dialog', 'inventory-uom-form-error', 'Unit of measure saved; it is available to Products, Stock Moves, Purchase, and Manufacturing.'],
        }[form.id];
        if (!config) return;
        try {
            const editing = Boolean(form.dataset.id);
            const url = editing ? `${config[0]}/${Number(form.dataset.id)}` : config[0];
            await api(url, { method: editing ? 'PATCH' : 'POST', body: JSON.stringify(data) });
            $('#' + config[1]).close(); form.reset();
            await loadInventoryOptions(String(companyId)); renderInventoryMasterData();
            showMessage($('#inventory-message'), config[3]);
        } catch (error) { setFormError(config[2], error.message); }
    }

    function renderInventoryWarehouses() {
        const options = state.inventoryOptions || {};
        const query = $('#warehouse-search').value.trim().toLocaleLowerCase();
        const allWarehouses = options.warehouse_records || options.warehouses || [];
        const allLocations = options.location_records || options.locations || [];
        const warehouses = allWarehouses.filter((row) => !query || `${row.name} ${row.code} ${row.partner_contact_name || ''}`.toLocaleLowerCase().includes(query));
        const locations = allLocations.filter((row) => !query || `${row.complete_name} ${row.warehouse_name || ''} ${row.location_usage} ${row.barcode || ''}`.toLocaleLowerCase().includes(query));
        const warehouseRows = $('#warehouse-rows'); warehouseRows.replaceChildren();
        if (!warehouses.length) {
            const row = warehouseRows.insertRow(); const cell = row.insertCell(); cell.colSpan = 5; cell.className = 'empty-cell';
            cell.textContent = options.company_id ? (query ? 'No warehouses match this search.' : 'No warehouses configured for this company. Create a warehouse to add its standard locations.') : 'Select a company to load warehouses.';
        }
        for (const warehouse of warehouses) {
            const row = warehouseRows.insertRow();
            row.insertCell().textContent = warehouse.name;
            row.insertCell().textContent = warehouse.code;
            row.insertCell().textContent = warehouse.partner_contact_name || '—';
            row.insertCell().textContent = String(allLocations.filter((location) => Number(location.warehouse_id) === Number(warehouse.id)).length);
            row.insertCell().textContent = Number(warehouse.active) ? 'Active' : 'Archived';
            renderWarehouseActions(row.insertCell(), warehouse);
        }
        const locationRows = $('#warehouse-location-rows'); locationRows.replaceChildren();
        if (!locations.length) {
            const row = locationRows.insertRow(); const cell = row.insertCell(); cell.colSpan = 10; cell.className = 'empty-cell';
            cell.textContent = options.company_id ? (query ? 'No locations match this search.' : 'No locations configured for this company.') : 'Select a company to load locations.';
        }
        for (const location of locations) {
            const row = locationRows.insertRow();
            row.insertCell().textContent = location.complete_name;
            row.insertCell().textContent = location.warehouse_name || 'Company-wide';
            const parent = allLocations.find((item) => Number(item.id) === Number(location.parent_location_id));
            row.insertCell().textContent = parent?.complete_name || '—';
            row.insertCell().textContent = String(location.location_usage).replace(/^./, (letter) => letter.toUpperCase());
            row.insertCell().textContent = location.barcode || '—';
            row.insertCell().textContent = Number(location.inventory_frequency_days || 0) ? String(location.inventory_frequency_days) : '—';
            row.insertCell().textContent = location.last_inventory_date || '—';
            row.insertCell().textContent = location.next_inventory_date || '—';
            row.insertCell().textContent = Number(location.active) ? 'Active' : 'Archived';
            renderLocationActions(row.insertCell(), location);
        }
        $('#warehouse-total').textContent = `${warehouses.length} warehouse${warehouses.length === 1 ? '' : 's'}`;
        $('#warehouse-location-total').textContent = `${locations.length} location${locations.length === 1 ? '' : 's'}`;
    }

    function openWarehouseCreate() {
        const companyId = Number($('#inventory-company').value);
        const company = (state.companies || []).find((row) => Number(row.id) === companyId);
        if (!companyId || !company) { showMessage($('#inventory-message'), 'Choose an allowed company before creating a warehouse.', true); return; }
        $('#warehouse-form').reset();
        $('#warehouse-form').dataset.id = '';
        $('#warehouse-company-context').textContent = `Company: ${company.name}. The warehouse and its standard locations will be created for this company.`;
        fillSelect($('#warehouse-partner'), state.inventoryOptions?.contacts || [], { placeholder: 'No linked partner', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        $('#warehouse-partner').disabled = !can('contacts.read');
        $('#warehouse-dialog-title').textContent = 'New Warehouse'; $('#warehouse-submit').textContent = 'Create Warehouse';
        setFormError('warehouse-form-error', '');
        $('#warehouse-dialog').showModal();
    }

    function openWarehouseEdit(warehouse) {
        const company = (state.companies || []).find((row) => Number(row.id) === Number($('#inventory-company').value));
        if (!company) { showMessage($('#inventory-message'), 'Choose an allowed company before editing a warehouse.', true); return; }
        const form = $('#warehouse-form'); form.reset(); form.dataset.id = String(warehouse.id);
        $('#warehouse-company-context').textContent = `Company: ${company.name}. Changing the short code also updates the warehouse root and every location path.`;
        const canReadContacts = can('contacts.read');
        fillSelect($('#warehouse-partner'), canReadContacts ? (state.inventoryOptions?.contacts || []) : [], { placeholder: canReadContacts ? 'No linked partner' : (warehouse.partner_contact_id ? 'Linked Contact · read-only' : 'No linked partner'), label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}`, keepValue: canReadContacts ? (warehouse.partner_contact_id || '') : '' });
        form.elements.partner_contact_id.disabled = !canReadContacts;
        form.elements.name.value = warehouse.name; form.elements.code.value = warehouse.code; if (canReadContacts) form.elements.partner_contact_id.value = warehouse.partner_contact_id || '';
        $('#warehouse-dialog-title').textContent = 'Edit Warehouse'; $('#warehouse-submit').textContent = 'Save';
        setFormError('warehouse-form-error', ''); $('#warehouse-dialog').showModal();
    }

    function renderWarehouseActions(cell, warehouse) {
        if (!can('inventory.write')) { cell.textContent = '—'; return; }
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openWarehouseEdit(warehouse)); cell.append(edit);
        const active = Number(warehouse.active) === 1;
        const status = document.createElement('button'); status.type = 'button'; status.className = 'button button-small'; status.textContent = active ? 'Archive' : 'Restore'; status.addEventListener('click', () => setWarehouseStatus(warehouse, !active)); cell.append(status);
    }

    function renderLocationActions(cell, location) {
        if (!can('inventory.write')) { cell.textContent = '—'; return; }
        if (location.warehouse_id === null || location.parent_location_id === null) { cell.textContent = 'System-managed'; return; }
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openLocationEdit(location)); cell.append(edit);
        const active = Number(location.active) === 1;
        const status = document.createElement('button'); status.type = 'button'; status.className = 'button button-small'; status.textContent = active ? 'Archive' : 'Restore'; status.addEventListener('click', () => setLocationStatus(location, !active)); cell.append(status);
    }

    async function setWarehouseStatus(warehouse, active) {
        const action = active ? 'restore' : 'archive';
        if (!window.confirm(`${active ? 'Restore' : 'Archive'} warehouse “${warehouse.name}”? ${active ? 'Its active transaction selectors will be available again after its locations are restored.' : 'Locations must be archived individually first.'}`)) return;
        const companyId = Number($('#inventory-company').value);
        try {
            await api(`/api/v1/warehouses/${Number(warehouse.id)}/status`, { method: 'PATCH', body: JSON.stringify({ company_id: companyId, active }) });
            await loadInventoryOptions(String(companyId)); renderInventoryWarehouses();
            showMessage($('#inventory-message'), `${warehouse.name} ${action}d.`);
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    async function setLocationStatus(location, active) {
        const action = active ? 'restore' : 'archive';
        if (!window.confirm(`${active ? 'Restore' : 'Archive'} location “${location.complete_name}”? ${active ? 'It will be available in active Inventory selectors again.' : 'Stock, pending moves, active counts, and active Manufacturing Orders must be cleared first.'}`)) return;
        const companyId = Number($('#inventory-company').value);
        try {
            await api(`/api/v1/stock-locations/${Number(location.id)}/status`, { method: 'PATCH', body: JSON.stringify({ company_id: companyId, active }) });
            await loadInventoryOptions(String(companyId)); renderInventoryWarehouses();
            showMessage($('#inventory-message'), `${location.complete_name} ${action}d.`);
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    function locationDescendantIds(locationId, records) {
        const children = new Map();
        for (const row of records) {
            if (!row.parent_location_id) continue;
            const parent = Number(row.parent_location_id);
            if (!children.has(parent)) children.set(parent, []);
            children.get(parent).push(Number(row.id));
        }
        const found = new Set([Number(locationId)]); const queue = [Number(locationId)];
        while (queue.length) for (const child of children.get(queue.shift()) || []) { if (!found.has(child)) { found.add(child); queue.push(child); } }
        return found;
    }

    function fillLocationParents(warehouseId, { currentLocationId = null, keepValue = '' } = {}) {
        const records = state.inventoryOptions?.location_records || [];
        const excluded = currentLocationId ? locationDescendantIds(currentLocationId, records) : new Set();
        const parents = records.filter((row) => Number(row.warehouse_id) === Number(warehouseId) && Number(row.active) === 1 && ['view', 'internal'].includes(row.location_usage) && !excluded.has(Number(row.id)));
        fillSelect($('#inventory-location-parent'), parents, { placeholder: 'Select parent location', keepValue, label: (row) => row.complete_name });
    }

    function syncInventoryLocationFrequency() {
        const viewOnly = $('#inventory-location-usage').value === 'view';
        const frequency = $('#inventory-location-frequency');
        if (viewOnly) frequency.value = '0';
        frequency.disabled = viewOnly;
    }

    function openLocationCreate() {
        const company = (state.companies || []).find((row) => Number(row.id) === Number($('#inventory-company').value));
        const warehouses = (state.inventoryOptions?.warehouse_records || []).filter((row) => Number(row.active) === 1);
        if (!company) { showMessage($('#inventory-message'), 'Choose an allowed company before creating a location.', true); return; }
        if (!warehouses.length) { showMessage($('#inventory-message'), 'Create or restore a warehouse before creating a location.', true); return; }
        const form = $('#inventory-location-form'); form.reset(); form.dataset.id = '';
        $('#inventory-location-company-context').textContent = `Company-specific stock location for ${company.name}. Location paths are calculated from the selected parent.`;
        fillSelect($('#inventory-location-warehouse'), warehouses, { placeholder: 'Select warehouse', label: (row) => `${row.name} (${row.code})` });
        $('#inventory-location-warehouse').value = String(warehouses[0].id);
        $('#inventory-location-warehouse').disabled = false; $('#inventory-location-usage').disabled = false;
        syncInventoryLocationFrequency();
        const warehouseId = $('#inventory-location-warehouse').value;
        fillLocationParents(warehouseId);
        const root = (state.inventoryOptions?.location_records || []).find((row) => Number(row.warehouse_id) === Number(warehouseId) && row.parent_location_id === null && row.location_usage === 'view' && Number(row.active) === 1);
        if (root) $('#inventory-location-parent').value = String(root.id);
        $('#inventory-location-dialog-title').textContent = 'New Stock Location'; $('#inventory-location-submit').textContent = 'Create Location';
        $('#inventory-location-help').textContent = 'Zero disables the cycle-count schedule. Last and next count dates are maintained by validated cycle counts.';
        setFormError('inventory-location-form-error', ''); $('#inventory-location-dialog').showModal();
    }

    function openLocationEdit(location) {
        const company = (state.companies || []).find((row) => Number(row.id) === Number($('#inventory-company').value));
        if (!company) { showMessage($('#inventory-message'), 'Choose an allowed company before editing a location.', true); return; }
        const form = $('#inventory-location-form'); form.reset(); form.dataset.id = String(location.id);
        $('#inventory-location-company-context').textContent = `Company: ${company.name}. Location Type and warehouse are fixed after creation; changing the parent is blocked after operational use.`;
        const warehouse = (state.inventoryOptions?.warehouse_records || []).find((row) => Number(row.id) === Number(location.warehouse_id));
        fillSelect($('#inventory-location-warehouse'), warehouse ? [warehouse] : [], { placeholder: 'Warehouse', label: (row) => `${row.name} (${row.code})`, keepValue: location.warehouse_id });
        $('#inventory-location-warehouse').disabled = true;
        fillLocationParents(location.warehouse_id, { currentLocationId: location.id, keepValue: location.parent_location_id || '' });
        form.elements.name.value = location.name; form.elements.parent_location_id.value = location.parent_location_id || '';
        form.elements.location_usage.value = location.location_usage; form.elements.location_usage.disabled = true;
        form.elements.barcode.value = location.barcode || ''; form.elements.inventory_frequency_days.value = Number(location.inventory_frequency_days || 0);
        syncInventoryLocationFrequency();
        $('#inventory-location-dialog-title').textContent = 'Edit Stock Location'; $('#inventory-location-submit').textContent = 'Save';
        $('#inventory-location-help').textContent = `Last Count: ${location.last_inventory_date || 'Never'} · Next Count: ${location.next_inventory_date || 'Not scheduled'}. These dates update when a cycle count is validated.`;
        setFormError('inventory-location-form-error', ''); $('#inventory-location-dialog').showModal();
    }

    async function saveInventoryLocation(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const companyId = Number($('#inventory-company').value);
        const data = Object.fromEntries(new FormData(form).entries());
        data.company_id = companyId;
        data.parent_location_id = Number(data.parent_location_id);
        data.inventory_frequency_days = data.inventory_frequency_days ? Number(data.inventory_frequency_days) : 0;
        data.barcode = String(data.barcode || '').trim() || null;
        const editing = Boolean(form.dataset.id);
        if (!editing) data.warehouse_id = Number($('#inventory-location-warehouse').value);
        try {
            const url = editing ? `/api/v1/stock-locations/${Number(form.dataset.id)}` : '/api/v1/stock-locations';
            await api(url, { method: editing ? 'PATCH' : 'POST', body: JSON.stringify(data) });
            $('#inventory-location-dialog').close(); form.reset();
            await loadInventoryOptions(String(companyId)); renderInventoryWarehouses();
            showMessage($('#inventory-message'), editing ? 'Stock location saved; descendant paths were refreshed.' : 'Stock location created and linked to its warehouse.');
        } catch (error) { setFormError('inventory-location-form-error', error.message); }
    }

    async function createWarehouse(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const companyId = Number($('#inventory-company').value);
        const data = Object.fromEntries(new FormData(form).entries());
        data.company_id = companyId;
        data.name = String(data.name || '').trim();
        data.code = String(data.code || '').trim().toUpperCase();
        data.partner_contact_id = data.partner_contact_id ? Number(data.partner_contact_id) : null;
        try {
            const editing = Boolean(form.dataset.id);
            const url = editing ? `/api/v1/warehouses/${Number(form.dataset.id)}` : '/api/v1/warehouses';
            await api(url, { method: editing ? 'PATCH' : 'POST', body: JSON.stringify(data) });
            $('#warehouse-dialog').close(); form.reset();
            await loadInventoryOptions(String(companyId));
            renderInventoryWarehouses();
            showMessage($('#inventory-message'), editing ? 'Warehouse saved; location paths and shared selectors were refreshed.' : 'Warehouse created with its standard stock locations. Sales and Purchase will use it for this company.');
        } catch (error) { setFormError('warehouse-form-error', error.message); }
    }

    async function loadProducts() {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) {
            state.products = []; state.totalProducts = 0; renderProducts();
            showMessage($('#inventory-message'), 'Choose an allowed company to view its products.');
            return;
        }
        const query = new URLSearchParams({ company_id: companyId, status: $('#product-status').value, q: $('#product-search').value.trim(), limit: '100', offset: '0' });
        const payload = await api('/api/v1/products?' + query);
        state.products = payload.records || []; state.totalProducts = Number(payload.total || 0);
        renderProducts();
    }

    function renderProducts() {
        const body = $('#product-rows'); body.replaceChildren();
        if (!state.products.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = can('inventory.read') ? 13 : 12; cell.className = 'empty-cell';
            cell.textContent = state.totalProducts ? 'No products match this search.' : 'No products yet. Create a product master record to begin.';
        }
        for (const product of state.products) {
            const row = body.insertRow();
            row.insertCell().textContent = product.internal_reference || '—';
            const nameCell = row.insertCell();
            const name = document.createElement('button'); name.type = 'button'; name.className = 'contact-link'; name.textContent = product.name;
            name.addEventListener('click', () => openProduct(Number(product.id))); nameCell.append(name);
            row.insertCell().textContent = product.category_name || '—';
            row.insertCell().textContent = product.product_class === 'cp' ? 'CP' : product.product_class === 'non_cp' ? 'Non-CP' : 'Unclassified';
            row.insertCell().textContent = String(product.product_type || '').replace(/^./, (letter) => letter.toUpperCase());
            row.insertCell().textContent = product.uom_name || '—';
            if (can('inventory.read')) row.insertCell().textContent = Number(product.on_hand_quantity || 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
            row.insertCell().textContent = product.cost_price;
            row.insertCell().textContent = product.list_price;
            row.insertCell().textContent = product.carton_price ?? '—';
            row.insertCell().textContent = product.tracking === 'lot' ? 'Lots' : product.tracking === 'serial' ? 'Serial' : 'Quantity';
            row.insertCell().textContent = Number(product.active) ? 'Active' : 'Archived';
            const action = row.insertCell();
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Open';
            edit.disabled = !(can('inventory.read') || can('products.create')); edit.addEventListener('click', () => openProduct(Number(product.id))); action.append(edit);
        }
        $('#product-total').textContent = `${state.totalProducts} product${state.totalProducts === 1 ? '' : 's'}`;
        const grid = $('#products-catalog-grid');
        if (grid) grid.innerHTML = state.products.length
            ? state.products.map((product) => sharedProductCatalogCard(product, { channel: 'inventory', actionLabel: 'Open Product', actionAttribute: 'data-open-product' })).join('')
            : `<div class="shared-product-catalog-empty"><strong>${state.totalProducts ? 'No products match this search.' : 'No products yet.'}</strong><p>Create a product in Inventory to add it to the shared catalog.</p></div>`;
        $('#products-table-view').hidden = state.productCatalogView !== 'table';
        grid.hidden = state.productCatalogView !== 'catalog';
        $('#product-view-catalog').classList.toggle('active', state.productCatalogView === 'catalog');
        $('#product-view-table').classList.toggle('active', state.productCatalogView === 'table');
        $('#product-view-catalog').setAttribute('aria-pressed', String(state.productCatalogView === 'catalog'));
        $('#product-view-table').setAttribute('aria-pressed', String(state.productCatalogView === 'table'));
    }

    function sharedProductCatalogCard(product, { channel = 'inventory', actionLabel = 'Select', actionAttribute = 'data-shared-catalog-select' } = {}) {
        const name = String(product.name || 'Product');
        const sku = String(product.internal_reference || product.sku || 'No reference');
        const category = String(product.category_name || product.category || 'Uncategorized');
        const className = product.product_class === 'cp' ? 'CP' : product.product_class === 'non_cp' ? 'Non-CP' : 'Unclassified';
        const classTone = product.product_class === 'cp' ? 'cp' : product.product_class === 'non_cp' ? 'non-cp' : 'other';
        const unit = String(product.uom_abbreviation || product.uom_name || 'Unit');
        const purchaseUnit = String(product.purchase_uom_abbreviation || product.purchase_uom_name || unit);
        const onHand = Number(product.on_hand_quantity || 0);
        const forecast = Number(product.forecast_quantity ?? product.on_hand_quantity ?? 0);
        const primaryPrice = product.display_price ?? (channel === 'purchase' ? product.cost_price : product.list_price);
        const priceLabel = product.display_price_label || (channel === 'purchase' ? 'Purchase Price' : 'Sales Price');
        const image = product.image ? `<img src="${escapeHtml(product.image)}" alt="">` : `<span aria-hidden="true">${escapeHtml(name.trim().slice(0, 1).toLocaleUpperCase() || 'P')}</span>`;
        const carton = product.carton_price === null || product.carton_price === undefined || product.carton_price === '' ? '' : `<span class="shared-product-catalog-carton"><small>Carton Price</small><strong>${escapeHtml(formatCurrencyAmount(product.carton_price, state.sharedProductCatalog?.currencyCode || state.inventoryOptions?.currency_code || state.purchaseOptions?.currency_code || state.salesOptions?.currency_code || state.invoicingOptions?.currency_code || 'BDT'))}</strong></span>`;
        const secondaryPrice = channel === 'inventory' ? `<span><small>Purchase Price</small><strong>${escapeHtml(formatCurrencyAmount(product.cost_price ?? 0, state.inventoryOptions?.currency_code || 'BDT'))}</strong></span>` : '';
        const primaryPriceMarkup = `<span><small>${escapeHtml(priceLabel)}</small><strong>${escapeHtml(formatCurrencyAmount(primaryPrice ?? 0, state.sharedProductCatalog?.currencyCode || state.inventoryOptions?.currency_code || state.purchaseOptions?.currency_code || state.salesOptions?.currency_code || state.invoicingOptions?.currency_code || 'BDT'))}<i>/ ${escapeHtml(channel === 'purchase' ? purchaseUnit : unit)}</i></strong></span>`;
        const action = product.active === 0 || product.active === '0'
            ? `<span class="shared-product-catalog-archived">Archived</span>`
            : state.sharedProductCatalog && !state.sharedProductCatalog.onSelect
                ? '<button type="button" class="button button-small" disabled aria-disabled="true">View only</button>'
                : `<button type="button" class="button button-small button-primary" ${actionAttribute}="${escapeHtml(product.id)}">${escapeHtml(actionLabel)}</button>`;
        return `<article class="shared-product-card"><header><div class="shared-product-card-title"><strong title="${escapeHtml(name)}">${escapeHtml(name)}</strong><small>${escapeHtml(sku)}</small></div><span class="shared-product-card-image" aria-hidden="true">${image}</span></header><div class="shared-product-card-badges"><span class="shared-product-badge shared-product-category" title="${escapeHtml(category)}">${escapeHtml(category)}</span><span class="shared-product-badge product-class-${classTone}">${escapeHtml(className)}</span><span class="shared-product-badge">${escapeHtml(String(product.product_type || 'goods').replace(/^./, (letter) => letter.toUpperCase()))}</span></div><div class="shared-product-card-prices">${primaryPriceMarkup}${secondaryPrice}${carton}</div><div class="shared-product-card-stock"><span><strong>${escapeHtml(Number(onHand).toLocaleString(undefined, { maximumFractionDigits: 3 }))}</strong> ${escapeHtml(unit)} on hand</span><span><strong>${escapeHtml(Number(forecast).toLocaleString(undefined, { maximumFractionDigits: 3 }))}</strong> forecasted</span></div><div class="shared-product-card-meta"><span>Sales ${escapeHtml(unit)}</span><span>Purchase ${escapeHtml(purchaseUnit)}</span>${product.barcode ? `<span>Barcode ${escapeHtml(product.barcode)}</span>` : ''}</div><footer>${action}</footer></article>`;
    }

    async function openSharedProductCatalog({ channel, companyId, productType = '', title = '', description = '', onSelect = null } = {}) {
        if (!companyId) throw new Error('Choose a company before opening the product catalog.');
        state.sharedProductCatalog = { channel, companyId: String(companyId), productType, query: '', categoryId: '', offset: 0, total: 0, records: [], categories: [], onSelect, requestId: 0 };
        $('#shared-product-catalog-search').value = '';
        $('#shared-product-catalog-category').replaceChildren(new Option('All categories', ''));
        $('#shared-product-catalog-title').textContent = title || (channel === 'purchase' ? 'Purchase Product Catalog' : channel === 'sales' ? 'Sales Product Catalog' : channel === 'invoice' ? 'Invoice Product Catalog' : 'Inventory Product Catalog');
        $('#shared-product-catalog-description').textContent = description || 'This view reads the same company product records used by Inventory.';
        $('#shared-product-catalog-grid').innerHTML = '<p class="empty-cell">Loading products…</p>';
        $('#shared-product-catalog-count').textContent = 'Loading…';
        $('#shared-product-catalog-more').hidden = true;
        $('#shared-product-catalog-dialog').showModal();
        await loadSharedProductCatalog(true);
        $('#shared-product-catalog-search').focus({ preventScroll: true });
    }

    async function loadSharedProductCatalog(reset = false) {
        const catalog = state.sharedProductCatalog;
        if (!catalog) return;
        const requestId = ++catalog.requestId;
        if (reset) { catalog.offset = 0; catalog.records = []; }
        const query = new URLSearchParams({ channel: catalog.channel, company_id: catalog.companyId, limit: '40', offset: String(catalog.offset), q: catalog.query });
        if (catalog.productType) query.set('product_type', catalog.productType);
        if (catalog.categoryId) query.set('category_id', catalog.categoryId);
        const grid = $('#shared-product-catalog-grid');
        if (reset) grid.innerHTML = '<p class="empty-cell">Loading products…</p>';
        $('#shared-product-catalog-message').hidden = true;
        const moreButton = $('#shared-product-catalog-more');
        moreButton.disabled = true;
        try {
            const result = await api('/api/v1/products/catalog?' + query.toString());
            if (state.sharedProductCatalog !== catalog || catalog.requestId !== requestId) return;
            catalog.total = Number(result.total || 0);
            catalog.records = reset ? (result.records || []) : [...catalog.records, ...(result.records || [])];
            catalog.categories = result.categories || catalog.categories;
            catalog.offset = catalog.records.length;
            grid.innerHTML = catalog.records.length
                ? catalog.records.map((product) => sharedProductCatalogCard(product, { channel: catalog.channel })).join('')
                : `<div class="shared-product-catalog-empty"><strong>No products found.</strong><p>Try another search or category.</p></div>`;
            $('#shared-product-catalog-count').textContent = `${catalog.total} active product${catalog.total === 1 ? '' : 's'}`;
            $('#shared-product-catalog-range').textContent = catalog.total ? `Showing ${catalog.records.length} of ${catalog.total}` : '';
            $('#shared-product-catalog-more').hidden = catalog.records.length >= catalog.total;
            const category = $('#shared-product-catalog-category');
            const keepCategory = catalog.categoryId;
            category.replaceChildren(new Option('All categories', ''));
            catalog.categories.forEach((row) => category.add(new Option(row.name, String(row.id))));
            if ([...category.options].some((option) => option.value === keepCategory)) category.value = keepCategory;
        } catch (error) {
            if (state.sharedProductCatalog !== catalog || catalog.requestId !== requestId) return;
            grid.innerHTML = `<div class="shared-product-catalog-empty"><strong>Catalog could not be loaded.</strong><p>${escapeHtml(error.message)}</p></div>`;
            $('#shared-product-catalog-message').hidden = false;
            $('#shared-product-catalog-message').textContent = error.message;
            $('#shared-product-catalog-count').textContent = 'Unavailable';
        } finally {
            if (state.sharedProductCatalog === catalog && catalog.requestId === requestId) moreButton.disabled = false;
        }
    }

    function selectSharedCatalogProduct(productId) {
        const catalog = state.sharedProductCatalog;
        const product = catalog?.records.find((item) => Number(item.id) === Number(productId));
        if (!catalog || !product) return;
        catalog.onSelect?.(product);
        $('#shared-product-catalog-dialog').close();
        state.sharedProductCatalog = null;
    }

    function openProductImport() {
        if (!can('products.create') && !can('inventory.write')) return;
        const companies = state.inventoryCompanies?.length ? state.inventoryCompanies : state.productCompanies?.length ? state.productCompanies : state.companies;
        const selected = $('#inventory-company').value || state.defaultCompanyId || companies[0]?.id || '';
        $('#product-import-form').reset();
        fillSelect($('#product-import-company'), companies, { placeholder: 'Select company', keepValue: String(selected) });
        $('#product-import-submit').disabled = false;
        $('#product-import-state').textContent = '';
        setFormError('product-import-error');
        $('#product-import-dialog').showModal();
    }

    async function importProducts(event) {
        event.preventDefault();
        const form = $('#product-import-form');
        if (!form.reportValidity()) return;
        const companyId = form.elements.company_id.value;
        const file = $('#product-import-file').files[0];
        if (!companyId || !file) return;
        setFormError('product-import-error');
        $('#product-import-submit').disabled = true;
        $('#product-import-state').textContent = 'Checking and importing rows…';
        const body = new FormData();
        body.set('company_id', companyId);
        body.set('file', file);
        try {
            const result = await api('/api/v1/products/import', { method: 'POST', body });
            $('#product-import-dialog').close();
            form.reset();
            if (state.page !== 'products' && can('inventory.read')) await loadProducts();
            const summary = `${result.total} product${Number(result.total) === 1 ? '' : 's'} imported · ${result.created} created · ${result.updated} updated.`;
            showMessage($('#inventory-message'), summary);
        } catch (error) {
            setFormError('product-import-error', error.message);
        } finally {
            $('#product-import-state').textContent = '';
            $('#product-import-submit').disabled = false;
        }
    }

    function selectProductTab(tabName = 'general') {
        const tabs = $$('#product-tabs [data-product-tab]');
        const selectedTab = tabs.find((tab) => tab.dataset.productTab === tabName) || tabs[0];
        if (!selectedTab) return;
        for (const tab of tabs) {
            const selected = tab === selectedTab;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            const panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) {
                panel.hidden = !selected;
                panel.classList.toggle('active', selected);
            }
        }
    }

    function updateProductFlowCards() {
        initializeProductFlowPanel();
        const form = $('#product-form');
        const saved = Boolean(form.dataset.id);
        const hasUnsavedChanges = form.dataset.dirty === '1';
        const canStartSales = can('sales.read') && can('sales.write');
        const canStartPurchase = can('purchase.read') && can('purchase.write');
        const saleAllowed = Boolean(form.elements.sale_ok.checked);
        const purchaseAllowed = Boolean(form.elements.purchase_ok.checked);
        const salesButton = $('#product-sales-flow');
        const purchaseButton = $('#product-purchase-flow');

        $('#product-sales-flow-copy').textContent = !saved ? 'Save product to start a quotation' : hasUnsavedChanges ? 'Save changes before starting a quotation' : !saleAllowed ? 'Enable Can be Sold to start a quotation' : !canStartSales ? 'Sales create access is not assigned' : 'Start a quotation with this product prefilled';
        $('#product-purchase-flow-copy').textContent = !saved ? 'Save product to start an RFQ' : hasUnsavedChanges ? 'Save changes before starting an RFQ' : !purchaseAllowed ? 'Enable Can be Purchased to start an RFQ' : !canStartPurchase ? 'Purchase create access is not assigned' : 'Start an RFQ with this product prefilled';
        const cartonPrice = form.elements.carton_price.value.trim();
        $('#product-sales-flow-value').textContent = `Piece ${form.elements.list_price.value || '0'} · Carton ${cartonPrice || '—'}`;
        $('#product-purchase-flow-value').textContent = `Cost ${form.elements.cost_price.value || '0'}`;
        salesButton.disabled = !saved || hasUnsavedChanges || !saleAllowed || !canStartSales;
        purchaseButton.disabled = !saved || hasUnsavedChanges || !purchaseAllowed || !canStartPurchase;
    }

    function initializeProductFlowPanel() {
        const panel = $('#product-smart-panel');
        if (!panel || panel.dataset.sharedPanel === '1') return;
        panel.outerHTML = window.ERPSharedSmartPanel.render({
            className: 'product-smart-panel', attributes: { id: 'product-smart-panel', 'data-shared-panel': '1' },
            ariaLabel: 'Sales and Purchase shortcuts',
            items: [
                { label: 'Sales', contentHtml: '<span class="product-smart-copy"><strong>Sales</strong><small id="product-sales-flow-copy">Save product to start a quotation</small></span><span id="product-sales-flow-value" class="product-smart-value">—</span>', iconHtml: '<span class="product-smart-icon" aria-hidden="true">↗</span>', className: 'product-smart-card product-smart-card-sales', attributes: { id: 'product-sales-flow' }, disabled: true },
                { label: 'Purchase', contentHtml: '<span class="product-smart-copy"><strong>Purchase</strong><small id="product-purchase-flow-copy">Save product to start an RFQ</small></span><span id="product-purchase-flow-value" class="product-smart-value">—</span>', iconHtml: '<span class="product-smart-icon" aria-hidden="true">↙</span>', className: 'product-smart-card product-smart-card-purchase', attributes: { id: 'product-purchase-flow' }, disabled: true },
            ],
        });
    }

    async function startProductOrderFlow(flow) {
        const form = $('#product-form');
        const productId = Number(form.dataset.id || 0);
        const companyId = String(form.elements.company_id.value || '');
        if (!productId || form.dataset.dirty === '1') return;
        $('#product-dialog').close();
        if (flow === 'sales') {
            if (!can('sales.read') || !can('sales.write') || !form.elements.sale_ok.checked) return;
            $('#sales-company').value = companyId;
            selectPage('sales');
            await openSalesOrder(null, { company_id: companyId, product_id: productId });
            return;
        }
        if (flow === 'purchase') {
            if (!can('purchase.read') || !can('purchase.write') || !form.elements.purchase_ok.checked) return;
            $('#purchase-company').value = companyId;
            selectPage('purchase');
            await openPurchaseOrder(null, { company_id: companyId, product_id: productId });
        }
    }

    async function openProduct(id = null) {
        if (id && !can('inventory.read')) return;
        if (!id && !can('products.create')) return;
        const form = $('#product-form');
        form.reset(); form.dataset.id = ''; form.dataset.dirty = '0';
        selectProductTab('general');
        $('#product-dialog-title').textContent = id ? 'Loading Product…' : 'New Product';
        $('#product-save-state').textContent = '';
        setFormError('product-form-error');
        const companyId = id ? null : ($('#inventory-company').value || '');
        if (!id && !companyId) { showMessage($('#inventory-message'), 'Select an allowed company before creating a product.', true); return; }
        try {
            let product = null;
            if (id) product = (await api(`/api/v1/products/${id}`)).product;
            const selectedCompany = product?.company_id || companyId;
            if (product) await loadInventoryOptions(selectedCompany);
            else await loadProductCatalogOptions(selectedCompany);
            if (product) {
                form.dataset.id = String(product.id);
                $('#product-dialog-title').textContent = product.name;
                for (const control of $$('[name]', form)) {
                    if (control.type === 'checkbox') control.checked = Boolean(Number(product[control.name]));
                    else if (product[control.name] !== undefined && product[control.name] !== null) control.value = String(product[control.name]);
                }
                form.elements.company_id.disabled = true;
            } else {
                form.elements.company_id.disabled = true;
                form.elements.company_id.value = String(selectedCompany);
                form.elements.list_price.value = '0'; form.elements.cost_price.value = '0';
                form.elements.product_class.value = 'unclassified';
                form.elements.carton_price.value = ''; form.elements.carton_size.value = ''; form.elements.units_per_carton.value = '';
                form.elements.weight.value = '0'; form.elements.volume.value = '0';
                form.elements.invoice_policy.value = 'ordered';
                form.elements.sale_ok.checked = true; form.elements.purchase_ok.checked = true; form.elements.active.checked = true;
                form.elements.income_account_id.value = '';
                form.elements.purchase_account_id.value = '';
            }
            const canEdit = product ? can('inventory.write') : can('products.create') && Boolean(state.inventoryOptions.product_create_access);
            const accountingAccess = state.inventoryOptions.accounting_access !== false;
            $$('[name]', form).forEach((control) => {
                if (control.name === 'company_id') { control.disabled = true; return; }
                control.disabled = !canEdit || (!accountingAccess && ['income_account_id', 'purchase_account_id'].includes(control.name));
            });
            form.querySelector('button[type="submit"]').hidden = !canEdit;
            updateProductFlowCards();
            $('#product-dialog').showModal();
        } catch (error) { setFormError('product-form-error', error.message); }
    }

    function productPayload(form) {
        return {
            company_id: form.elements.company_id.value,
            name: form.elements.name.value.trim(),
            internal_reference: form.elements.internal_reference.value.trim(),
            barcode: form.elements.barcode.value.trim(),
            product_type: form.elements.product_type.value,
            product_class: form.elements.product_class.value,
            category_id: form.elements.category_id.value,
            uom_id: form.elements.uom_id.value,
            purchase_uom_id: form.elements.purchase_uom_id.value,
            tracking: form.elements.tracking.value,
            invoice_policy: form.elements.invoice_policy.value,
            sale_ok: form.elements.sale_ok.checked,
            purchase_ok: form.elements.purchase_ok.checked,
            active: form.elements.active.checked,
            list_price: form.elements.list_price.value || '0',
            carton_price: form.elements.carton_price.value,
            carton_size: form.elements.carton_size.value,
            units_per_carton: form.elements.units_per_carton.value,
            income_account_id: form.elements.income_account_id.disabled ? undefined : (form.elements.income_account_id.value ? Number(form.elements.income_account_id.value) : null),
            purchase_account_id: form.elements.purchase_account_id.disabled ? undefined : (form.elements.purchase_account_id.value ? Number(form.elements.purchase_account_id.value) : null),
            cost_price: form.elements.cost_price.value || '0',
            weight: form.elements.weight.value || '0',
            volume: form.elements.volume.value || '0',
            description: form.elements.description.value,
        };
    }

    async function saveProduct(event) {
        event.preventDefault(); const form = $('#product-form');
        const hiddenInvalid = [...form.querySelectorAll(':invalid')].find((control) => control.closest('.product-tab-panel')?.hidden);
        if (hiddenInvalid) {
            const panel = hiddenInvalid.closest('.product-tab-panel');
            const tab = $(`#product-tabs [aria-controls="${panel.id}"]`);
            if (tab) selectProductTab(tab.dataset.productTab);
            hiddenInvalid.reportValidity();
            return;
        }
        if (!form.reportValidity()) return;
        setFormError('product-form-error'); $('#product-save-state').textContent = 'Saving…';
        try {
            const id = form.dataset.id;
            const result = await api(id ? `/api/v1/products/${id}` : '/api/v1/products', {
                method: id ? 'PATCH' : 'POST', body: JSON.stringify(productPayload(form)),
            });
            form.dataset.dirty = '0';
            $('#product-dialog').close();
            if (state.page === 'products' || !can('inventory.read')) {
                form.reset();
                showMessage($('#inventory-message'), `Product created: ${result.product?.name || 'record saved'}. Product list access is not enabled for this user.`);
                return;
            }
            $('#inventory-company').value = form.elements.company_id.value;
            await loadProducts();
            showMessage($('#inventory-message'), id ? 'Product updated.' : 'Product created.');
        } catch (error) { setFormError('product-form-error', error.message); }
        finally { $('#product-save-state').textContent = ''; }
    }

    async function loadMoveProducts(companyId) {
        const query = new URLSearchParams({ company_id: companyId, status: 'active', limit: '200', offset: '0' });
        const payload = await api('/api/v1/products?' + query);
        state.moveProducts = (payload.records || []).filter((product) => product.product_type === 'goods');
        fillSelect($('#move-product'), state.moveProducts, { placeholder: 'Select a goods product', label: (row) => `${row.name}${row.internal_reference ? ` · ${row.internal_reference}` : ''}` });
    }

    function updateMoveProductOptions(selectedUom = '') {
        const productId = Number($('#move-product').value || 0);
        const product = state.moveProducts.find((row) => Number(row.id) === productId);
        const units = state.inventoryOptions.units || [];
        const categoryId = units.find((unit) => Number(unit.id) === Number(product?.uom_id))?.category_id;
        const compatible = categoryId ? units.filter((unit) => Number(unit.category_id) === Number(categoryId)) : units;
        fillSelect($('#move-uom'), compatible, { placeholder: 'Select unit', label: (row) => `${row.name} (${row.abbreviation})`, keepValue: selectedUom || product?.uom_id || '' });
        if (product) {
            $('#move-lines-help').textContent = product.tracking === 'none'
                ? 'Untracked products use one quantity line. Enter the quantity to ship as Done Quantity; the remainder becomes a backorder.'
                : product.tracking === 'lot' ? 'Enter a lot and quantity on each detailed operation line. Done Quantity creates a backorder for any unshipped lot quantity.' : 'Enter one unique serial for each one unit operation line. Complete whole serials; unshipped serials stay on the backorder.';
            $('#add-move-line').hidden = product.tracking === 'none';
            $$('.move-line', $('#move-lines')).forEach((line) => updateMoveLineTracking(line, product.tracking));
        } else $('#add-move-line').hidden = true;
    }

    function updateMoveLineTracking(line, tracking) {
        const tracked = tracking !== 'none';
        line.querySelector('[data-line-lot]').hidden = !tracked;
        line.querySelector('[data-line-expiration]').hidden = !tracked;
        line.querySelector('[data-line-lot]').required = tracked;
        line.querySelector('[data-line-remove]').hidden = !tracked;
    }

    function addMoveLine(value = {}) {
        const line = document.createElement('div'); line.className = 'move-line';
        line.dataset.lotId = value.lot_id || '';
        line.dataset.lineId = value.id || '';
        const lotLabel = document.createElement('label'); lotLabel.dataset.lineLot = '';
        lotLabel.textContent = 'Lot / Serial';
        const lot = document.createElement('input'); lot.name = 'lot_code'; lot.maxLength = 120; lot.value = value.lot_code || ''; lotLabel.append(lot);
        const expiryLabel = document.createElement('label'); expiryLabel.dataset.lineExpiration = '';
        expiryLabel.textContent = 'Expiration';
        const expiration = document.createElement('input'); expiration.name = 'expiration_date'; expiration.type = 'date'; expiration.value = value.expiration_date || ''; expiryLabel.append(expiration);
        const quantityLabel = document.createElement('label'); quantityLabel.textContent = 'Quantity';
        const quantity = document.createElement('input'); quantity.name = 'quantity'; quantity.type = 'number'; quantity.min = '0.000001'; quantity.step = '0.000001'; quantity.required = true; quantity.value = value.quantity || ($$('.move-line', $('#move-lines')).length ? '1' : ($('#stock-move-form').elements.demand_quantity.value || '1')); quantityLabel.append(quantity);
        const doneLabel = document.createElement('label'); doneLabel.dataset.doneQuantityLabel = ''; doneLabel.hidden = true; doneLabel.textContent = 'Done Quantity';
        const doneQuantity = document.createElement('input'); doneQuantity.name = 'quantity_done'; doneQuantity.type = 'number'; doneQuantity.min = '0'; doneQuantity.step = '0.000001'; doneQuantity.value = value.quantity_done ?? value.quantity ?? ''; doneLabel.append(doneQuantity);
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-small move-line-remove'; remove.dataset.lineRemove = ''; remove.textContent = 'Remove'; remove.addEventListener('click', () => line.remove());
        line.append(lotLabel, expiryLabel, quantityLabel, doneLabel, remove);
        const product = state.moveProducts.find((row) => Number(row.id) === Number($('#move-product').value));
        updateMoveLineTracking(line, product?.tracking || 'none');
        if (product?.tracking === 'none') remove.hidden = true;
        $('#move-lines').append(line);
        return line;
    }

    async function openStockMove(id = null) {
        state.currentStockMove = null;
        $('#stock-move-return').hidden = true;
        $('#stock-move-supplier-return').hidden = true;
        if (!id && !can('inventory.write')) return;
        const form = $('#stock-move-form'); form.reset(); form.dataset.id = '';
        $('#move-lines').replaceChildren(); $('#stock-move-dialog-title').textContent = id ? 'Loading Stock Move…' : 'New Stock Move';
        $('#stock-move-save-state').textContent = ''; setFormError('stock-move-form-error');
        try {
            const move = id ? (await api(`/api/v1/stock-moves/${id}`)).move : null;
            state.currentStockMove = move;
            const companyId = move?.company_id || $('#inventory-company').value || state.defaultCompanyId;
            if (!companyId) throw new Error('Select an allowed company before creating a stock move.');
            await loadInventoryOptions(companyId);
            await loadMoveProducts(companyId);
            if (move) {
                form.dataset.id = String(move.id);
                $('#stock-move-dialog-title').textContent = `${move.return_of_move_id && move.origin_model === 'purchase_order' ? 'Supplier Return' : move.return_of_move_id ? 'Customer Return' : 'Stock Move'} · ${move.reference} · ${move.state}`;
                form.elements.company_id.value = String(move.company_id);
                $('#move-product').value = String(move.product_id);
                updateMoveProductOptions(move.uom_id);
                form.elements.source_location_id.value = String(move.source_location_id);
                form.elements.destination_location_id.value = String(move.destination_location_id);
                form.elements.demand_quantity.value = move.demand_quantity;
                form.elements.scheduled_at.value = move.scheduled_at ? String(move.scheduled_at).replace(' ', 'T').slice(0, 16) : '';
                form.elements.origin_model.value = move.origin_model === 'sale_order' ? 'sales' : (move.origin_model || '');
                form.elements.origin_record_id.value = move.origin_record_id || '';
                form.elements.origin_reference.value = move.origin_reference || '';
                for (const line of move.lines || []) addMoveLine(line);
                if (!(move.lines || []).length) addMoveLine({ quantity: move.demand_quantity });
            } else {
                form.elements.company_id.value = String(companyId);
                form.elements.demand_quantity.value = '1';
                updateMoveProductOptions();
            }
            const validateMode = Boolean(move && move.state === 'confirmed' && can('inventory.validate'));
            const readOnly = !can('inventory.write') || (move && move.state !== 'draft');
            $('#stock-move-return').hidden = !(move && move.state === 'done' && move.origin_model === 'sale_order' && move.destination_usage === 'customer' && can('inventory.write') && (move.lines || []).some((line) => Number(line.returnable_quantity) > 0));
            $('#stock-move-supplier-return').hidden = !(move && move.state === 'done' && move.origin_model === 'purchase_order' && move.source_usage === 'vendor' && ['internal','transit'].includes(move.destination_usage) && can('inventory.write') && (move.lines || []).some((line) => Number(line.returnable_quantity) > 0));
            form.dataset.mode = validateMode ? 'validate' : 'draft';
            for (const control of $$('input,select', form)) control.disabled = Boolean(readOnly);
            for (const label of $$('[data-done-quantity-label]', form)) {
                label.hidden = !validateMode;
                const input = $('input', label);
                input.disabled = !validateMode;
                if (validateMode) input.max = input.closest('.move-line').querySelector('[name="quantity"]').value;
            }
            $('#add-move-line').disabled = Boolean(readOnly);
            $('#move-product-catalog').hidden = Boolean(readOnly);
            $$('.move-line-remove', form).forEach((button) => { button.disabled = Boolean(readOnly); });
            const saveButton = $('button[type="submit"]', form); saveButton.hidden = Boolean(readOnly && !validateMode); saveButton.textContent = validateMode ? 'Validate Move' : 'Save Draft';
            $('.dialog-foot [data-close="stock-move-dialog"]', form).textContent = readOnly ? 'Close' : 'Discard';
            $('#stock-move-dialog').showModal();
        } catch (error) { setFormError('stock-move-form-error', error.message); }
    }

    function openCustomerReturnDialog(move, supplier = false) {
        const form = $('#customer-return-form'); form.reset(); form.dataset.deliveryId = String(move.id);
        form.dataset.kind = supplier ? 'supplier' : 'customer';
        $('#stock-return-eyebrow').textContent = supplier ? 'INVENTORY · SUPPLIER RETURN' : 'INVENTORY · CUSTOMER RETURN';
        $('#customer-return-title').textContent = supplier ? `Return to Supplier · ${move.reference}` : `Return from Customer · ${move.reference}`;
        $('#customer-return-delivery').textContent = supplier ? `${move.product_name} · Purchase receipt ${move.reference} · Received ${move.done_quantity} ${move.uom_abbreviation || ''} · Destination ${move.source_location}` : `${move.product_name} · Sales delivery ${move.origin_reference || ''} · Delivered ${move.done_quantity} ${move.uom_abbreviation || ''}`;
        $('#customer-return-location-label').hidden = supplier;
        $('#customer-return-location').required = !supplier;
        $('#stock-return-lines-title').textContent = supplier ? 'Received Lot / Serial Lines' : 'Delivered Lot / Serial Lines';
        $('#stock-return-lines-help').textContent = supplier ? 'Select each received line to return. Quantities cannot exceed the unreturned received quantity.' : 'Select each delivered line to return. Quantities cannot exceed the unreturned delivered quantity.';
        const locations = (state.inventoryOptions.locations || []).filter((location) => ['internal', 'transit'].includes(location.location_usage));
        fillSelect($('#customer-return-location'), locations, { placeholder: 'Select return location', label: (location) => `${location.complete_name} · ${location.location_usage}` });
        if (locations.some((location) => Number(location.id) === Number(move.source_location_id))) $('#customer-return-location').value = String(move.source_location_id);
        const container = $('#customer-return-lines'); container.replaceChildren();
        for (const sourceLine of (move.lines || []).filter((line) => Number(line.returnable_quantity) > 0)) {
            const row = document.createElement('div'); row.className = 'customer-return-line'; row.dataset.sourceLineId = String(sourceLine.id);
            const includeLabel = document.createElement('label'); includeLabel.className = 'check-row';
            const include = document.createElement('input'); include.type = 'checkbox'; include.dataset.returnInclude = ''; includeLabel.append(include, document.createTextNode('Return this line'));
            const detail = document.createElement('div'); detail.className = 'customer-return-line-detail';
            const title = document.createElement('strong'); title.textContent = sourceLine.lot_code ? `Lot / Serial ${sourceLine.lot_code}` : 'Untracked product';
            const available = document.createElement('small'); available.textContent = `Available to return: ${sourceLine.returnable_quantity} ${move.uom_abbreviation || ''}`; detail.append(title, available);
            const quantityLabel = document.createElement('label'); quantityLabel.textContent = 'Return Quantity';
            const quantity = document.createElement('input'); quantity.type = 'number'; quantity.min = '0.000001'; quantity.max = String(sourceLine.returnable_quantity); quantity.step = '0.000001'; quantity.value = String(sourceLine.returnable_quantity); quantity.disabled = true; quantity.dataset.returnQuantity = ''; quantityLabel.append(quantity);
            include.addEventListener('change', () => { quantity.disabled = !include.checked; quantity.required = include.checked; });
            row.append(includeLabel, detail, quantityLabel); container.append(row);
        }
        setFormError('customer-return-error'); $('#customer-return-save-state').textContent = ''; $('#stock-return-submit').textContent = supplier ? 'Create Supplier Return' : 'Create Customer Return';
        $('#customer-return-dialog').showModal();
    }

    async function createCustomerReturn(event) {
        event.preventDefault(); const form = $('#customer-return-form'); if (!form.reportValidity()) return;
        const lines = $$('.customer-return-line', $('#customer-return-lines')).filter((row) => row.querySelector('[data-return-include]').checked).map((row) => ({ source_line_id: Number(row.dataset.sourceLineId), quantity: row.querySelector('[data-return-quantity]').value }));
        if (!lines.length) { setFormError('customer-return-error', 'Select at least one delivered line to return.'); return; }
        setFormError('customer-return-error'); $('#customer-return-save-state').textContent = 'Creating return…';
        try {
            const deliveryId = Number(form.dataset.deliveryId);
            const supplier = form.dataset.kind === 'supplier';
            const endpoint = supplier ? `/api/v1/stock-moves/${deliveryId}/supplier-returns` : `/api/v1/stock-moves/${deliveryId}/returns`;
            const body = { return_reason: form.elements.return_reason.value.trim(), lines };
            if (!supplier) body.return_location_id = Number(form.elements.return_location_id.value);
            const response = await api(endpoint, { method: 'POST', body: JSON.stringify(body) });
            $('#customer-return-dialog').close();
            $('#inventory-company').value = String(response.move.company_id);
            await Promise.all([loadStockMoves(), loadStockLevels(), loadProducts()]);
            showMessage($('#inventory-message'), supplier ? `${response.move.reference} created and confirmed. Validate the return to send the goods back to the supplier.` : `${response.move.reference} created and confirmed. Validate the return to receive the goods into stock.`);
            await openStockMove(Number(response.move.id));
        } catch (error) { setFormError('customer-return-error', error.message); }
        finally { $('#customer-return-save-state').textContent = ''; }
    }

    function stockMovePayload(form) {
        const lines = $$('.move-line', $('#move-lines')).map((line) => {
            const data = { quantity: line.querySelector('[name="quantity"]').value };
            const product = state.moveProducts.find((row) => Number(row.id) === Number(form.elements.product_id.value));
            if (product?.tracking !== 'none') {
                data.lot_code = line.querySelector('[name="lot_code"]').value.trim();
                data.expiration_date = line.querySelector('[name="expiration_date"]').value || null;
                if (line.dataset.lotId) data.lot_id = Number(line.dataset.lotId);
            }
            return data;
        });
        return {
            company_id: Number(form.elements.company_id.value), product_id: Number(form.elements.product_id.value),
            uom_id: Number(form.elements.uom_id.value), source_location_id: Number(form.elements.source_location_id.value),
            destination_location_id: Number(form.elements.destination_location_id.value), demand_quantity: form.elements.demand_quantity.value,
            scheduled_at: form.elements.scheduled_at.value || null, origin_model: form.elements.origin_model.value || null,
            origin_record_id: form.elements.origin_record_id.value ? Number(form.elements.origin_record_id.value) : null,
            origin_reference: form.elements.origin_reference.value.trim() || null, lines,
        };
    }

    async function saveStockMove(event) {
        event.preventDefault(); const form = $('#stock-move-form'); if (!form.reportValidity()) return;
        setFormError('stock-move-form-error'); $('#stock-move-save-state').textContent = 'Saving…';
        try {
            const id = form.dataset.id;
            if (form.dataset.mode === 'validate') {
                const doneQuantities = $$('.move-line', $('#move-lines')).map((line) => ({ line_id: Number(line.dataset.lineId), quantity: line.querySelector('[name="quantity_done"]').value }));
                const response = await api(`/api/v1/stock-moves/${id}/validate`, { method: 'POST', body: JSON.stringify({ done_quantities: doneQuantities }) });
                $('#stock-move-dialog').close();
                $('#inventory-company').value = form.elements.company_id.value;
                await Promise.all([loadStockMoves(), loadStockLevels(), loadProducts()]);
                const backorder = response.move?.backorder;
                showMessage($('#inventory-message'), backorder ? `Stock move validated. Backorder ${backorder.reference} is confirmed for the remaining quantity.` : 'Stock move validated.');
                return;
            }
            await api(id ? `/api/v1/stock-moves/${id}` : '/api/v1/stock-moves', {
                method: id ? 'PATCH' : 'POST', body: JSON.stringify(stockMovePayload(form)),
            });
            $('#stock-move-dialog').close();
            $('#inventory-company').value = form.elements.company_id.value;
            await loadStockMoves(); await loadProducts();
            showMessage($('#inventory-message'), id ? 'Stock move draft updated.' : 'Stock move draft created.');
        } catch (error) { setFormError('stock-move-form-error', error.message); }
        finally { $('#stock-move-save-state').textContent = ''; }
    }

    async function loadStockMoves() {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) { state.stockMoves = []; state.totalStockMoves = 0; renderStockMoves(); return; }
        const query = new URLSearchParams({ company_id: companyId, q: $('#move-search').value.trim(), state: $('#move-status').value, limit: '200', offset: '0' });
        const payload = await api('/api/v1/stock-moves?' + query);
        state.stockMoves = payload.records || []; state.totalStockMoves = Number(payload.total || 0); renderStockMoves();
    }

    function renderStockMoves() {
        const body = $('#move-rows'); body.replaceChildren();
        if (!state.stockMoves.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 9; cell.className = 'empty-cell';
            cell.textContent = state.totalStockMoves ? 'No stock moves match this search.' : 'No stock moves yet. Create a receipt, transfer, or delivery.';
        }
        for (const move of state.stockMoves) {
            const row = body.insertRow();
            row.insertCell().textContent = move.return_of_move_id ? `${move.reference} · Return of ${move.return_of_reference || `Move ${move.return_of_move_id}`}` : move.reference;
            row.insertCell().textContent = `${move.product_name}${move.internal_reference ? ` · ${move.internal_reference}` : ''}`;
            row.insertCell().textContent = move.source_location || '—'; row.insertCell().textContent = move.destination_location || '—';
            row.insertCell().textContent = move.demand_quantity; row.insertCell().textContent = move.done_quantity;
            row.insertCell().textContent = move.state === 'done' ? formatCurrencyAmount(move.valuation_value, move.currency_code) : '—';
            const status = document.createElement('span'); status.className = `status-pill ${move.state}`; status.textContent = move.state; row.insertCell().append(status);
            row.insertCell().textContent = [move.origin_reference, move.return_reason].filter(Boolean).join(' · ') || '—';
            const actions = row.insertCell(); actions.className = 'row-actions';
            const addAction = (label, callback, disabled = false) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = label; button.disabled = disabled; button.addEventListener('click', callback); actions.append(button); };
            addAction('Open', () => openStockMove(Number(move.id)));
            if (move.state === 'draft') addAction('Confirm', () => runStockMoveAction(move.id, 'confirm'), !can('inventory.write'));
            if (move.state === 'confirmed') addAction('Process / Validate', () => openStockMove(Number(move.id)), !can('inventory.validate'));
            if (['draft', 'confirmed'].includes(move.state)) addAction('Cancel', () => runStockMoveAction(move.id, 'cancel'), !can('inventory.write'));
        }
        $('#move-total').textContent = `${state.totalStockMoves} stock move${state.totalStockMoves === 1 ? '' : 's'}`;
    }

    async function runStockMoveAction(id, action) {
        const prompts = { confirm: 'Reserve the available source stock for this move?', validate: 'Validate this move and post the quantity changes to stock?', cancel: 'Cancel this draft or reservation?' };
        if (!window.confirm(prompts[action])) return;
        try {
            await api(`/api/v1/stock-moves/${id}/${action}`, { method: 'POST', body: JSON.stringify({}) });
            await Promise.all([loadStockMoves(), loadStockLevels(), loadProducts()]);
            const completed = { confirm: 'confirmed', validate: 'validated', cancel: 'cancelled' };
            showMessage($('#inventory-message'), `Stock move ${completed[action]}.`);
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    async function loadStockLevels() {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) { state.stockLevels = []; renderStockLevels(); return; }
        const payload = await api('/api/v1/stock-levels?company_id=' + encodeURIComponent(companyId));
        state.stockLevels = payload.records || []; renderStockLevels();
    }

    function populateCycleCountLocations(companyId, selected = '') {
        const locations = state.inventoryCycleCountOptions.locations || (state.inventoryOptions.locations || []).filter((location) => location.location_usage === 'internal');
        fillSelect($('#cycle-count-location'), locations, {
            placeholder: 'Select an Internal location', keepValue: selected,
            label: (location) => `${location.complete_name} · Internal`,
        });
        fillSelect($('#cycle-count-company'), state.companies, { placeholder: 'Select company', keepValue: companyId });
        fillSelect($('#cycle-count-assignee'), state.inventoryCycleCountOptions.assignees || [], { placeholder: 'No assigned user' });
    }

    async function openInventoryCycleCountCreate() {
        const form = $('#inventory-cycle-count-create-form'); form.reset();
        setFormError('cycle-count-create-error');
        const companyId = $('#inventory-company').value || state.defaultCompanyId || '';
        if (!companyId) throw new Error('Select a company before creating a cycle count.');
        await loadInventoryOptions(companyId);
        await loadInventoryCycleCountOptions(companyId);
        populateCycleCountLocations(companyId);
        form.elements.note.value = 'Physical Inventory';
        form.elements.scheduled_at.value = accountingToday();
        $('#inventory-cycle-count-create-dialog').showModal();
    }

    async function loadInventoryCycleCountOptions(companyId) {
        if (!companyId) { state.inventoryCycleCountOptions = { locations: [], assignees: [] }; return; }
        const payload = await api('/api/v1/inventory/cycle-count-options?company_id=' + encodeURIComponent(companyId));
        state.inventoryCycleCountOptions = payload.options || { locations: [], assignees: [] };
        renderInventoryCycleCountSchedules();
    }

    function renderInventoryCycleCountSchedules() {
        const body = $('#cycle-count-schedule-rows');
        if (!body) return;
        body.replaceChildren();
        const locations = state.inventoryCycleCountOptions.locations || [];
        if (!locations.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 5; cell.className = 'empty-cell';
            cell.textContent = 'No active Internal locations are available in this company.'; return;
        }
        for (const location of locations) {
            const row = body.insertRow(); row.insertCell().textContent = location.complete_name;
            const frequencyCell = row.insertCell(); const wrap = document.createElement('div'); wrap.className = 'cycle-count-frequency-edit';
            const input = document.createElement('input'); input.type = 'number'; input.min = '0'; input.max = '3650'; input.step = '1';
            input.value = String(location.inventory_frequency_days || 0); input.disabled = !can('inventory.write');
            input.setAttribute('aria-label', `Inventory frequency for ${location.complete_name} in days`);
            const days = document.createElement('span'); days.textContent = 'days';
            const save = document.createElement('button'); save.type = 'button'; save.className = 'button button-small'; save.textContent = 'Save';
            save.disabled = !can('inventory.write'); save.addEventListener('click', () => saveInventoryCycleCountSchedule(Number(location.id), input.value));
            wrap.append(input, days, save); frequencyCell.append(wrap);
            row.insertCell().textContent = location.last_inventory_date || '—';
            row.insertCell().textContent = location.next_inventory_date || '—';
            row.insertCell().textContent = location.inventory_frequency_days > 0 ? 'Scheduled' : 'Manual';
        }
    }

    async function saveInventoryCycleCountSchedule(locationId, frequency) {
        try {
            await api(`/api/v1/inventory/locations/${locationId}/cycle-count-schedule`, {
                method: 'PATCH', body: JSON.stringify({ inventory_frequency_days: frequency }),
            });
            await loadInventoryCycleCountOptions($('#inventory-company').value);
            if (state.currentInventoryCycleCount?.location_id === locationId) await reloadInventoryCycleCountDetail();
            showMessage($('#inventory-message'), 'Location cycle count schedule saved.');
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    async function createInventoryCycleCount(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        setFormError('cycle-count-create-error');
        try {
            const response = await api('/api/v1/inventory/cycle-counts', {
                method: 'POST', body: JSON.stringify({
                    company_id: Number(form.elements.company_id.value), location_id: Number(form.elements.location_id.value),
                    assigned_to: form.elements.assigned_to.value ? Number(form.elements.assigned_to.value) : null,
                    scheduled_at: form.elements.scheduled_at.value || null, show_expected_quantity: form.elements.show_expected_quantity.checked,
                    note: form.elements.note.value.trim() || 'Physical Inventory',
                }),
            });
            $('#inventory-company').value = String(response.cycle_count.company_id);
            $('#inventory-cycle-count-create-dialog').close();
            await loadInventoryOptions(String(response.cycle_count.company_id));
            await loadProducts();
            await loadInventoryCycleCounts();
            await openInventoryCycleCountDetail(Number(response.cycle_count.id));
        } catch (error) { setFormError('cycle-count-create-error', error.message); }
    }

    async function loadInventoryCycleCounts(append = false) {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) {
            state.inventoryCycleCounts = []; state.inventoryCycleCountTotal = 0; renderInventoryCycleCounts(); return;
        }
        const offset = append ? state.inventoryCycleCounts.length : 0;
        const query = new URLSearchParams({
            company_id: companyId, q: $('#cycle-count-search').value.trim(), state: $('#cycle-count-status').value,
            limit: '100', offset: String(offset),
        });
        const result = await api('/api/v1/inventory/cycle-counts?' + query);
        state.inventoryCycleCounts = append ? state.inventoryCycleCounts.concat(result.records || []) : (result.records || []);
        state.inventoryCycleCountTotal = Number(result.total || 0);
        renderInventoryCycleCounts();
    }

    function renderInventoryCycleCounts() {
        const body = $('#cycle-count-rows'); body.replaceChildren();
        if (!state.inventoryCycleCounts.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 11; cell.className = 'empty-cell';
            cell.textContent = state.inventoryCycleCountTotal ? 'No cycle counts match this search.' : 'No cycle counts yet. Create one to start a physical count.';
        }
        for (const count of state.inventoryCycleCounts) {
            const row = body.insertRow();
            const ref = row.insertCell();
            const open = document.createElement('button'); open.type = 'button'; open.className = 'contact-link'; open.textContent = count.reference;
            open.addEventListener('click', () => openInventoryCycleCountDetail(Number(count.id)));
            ref.append(open);
            row.insertCell().textContent = count.location_name;
            const status = document.createElement('span'); status.className = `status-pill ${count.state === 'done' ? 'done' : count.state}`;
            status.textContent = count.state === 'counting' ? 'In Progress' : count.state === 'done' ? 'Validated' : 'Cancelled'; row.insertCell().append(status);
            row.insertCell().textContent = count.assigned_to_name || '—';
            row.insertCell().textContent = count.scheduled_at || '—';
            row.insertCell().textContent = `${count.counted_line_count || 0} / ${count.line_count || 0}`;
            row.insertCell().textContent = String(count.difference_line_count || 0);
            row.insertCell().textContent = `${count.created_by_name || '—'} · ${count.created_at || ''}`;
            row.insertCell().textContent = count.validated_by_name ? `${count.validated_by_name} · ${count.validated_at || ''}` : '—';
            const accountingCell = row.insertCell();
            if (count.accounting_move_id && can('accounting.read')) {
                const entry = document.createElement('button'); entry.type = 'button'; entry.className = 'link-button';
                entry.textContent = `${count.accounting_move_name || 'Journal Entry'} · ${count.accounting_move_state || '—'}`;
                entry.addEventListener('click', () => openAccountingMove(Number(count.accounting_move_id)));
                accountingCell.append(entry);
            } else accountingCell.textContent = count.accounting_move_id ? 'Restricted' : '—';
            const action = row.insertCell();
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = 'Open';
            button.addEventListener('click', () => openInventoryCycleCountDetail(Number(count.id))); action.append(button);
        }
        $('#cycle-count-total').textContent = `${state.inventoryCycleCountTotal} cycle count${state.inventoryCycleCountTotal === 1 ? '' : 's'}`;
        const more = $('#cycle-counts-more');
        more.hidden = state.inventoryCycleCounts.length >= state.inventoryCycleCountTotal;
        more.textContent = `Load more (${Math.max(0, state.inventoryCycleCountTotal - state.inventoryCycleCounts.length)} remaining)`;
        more.disabled = false;
    }

    async function openInventoryCycleCountDetail(id) {
        setFormError('cycle-count-detail-error'); showMessage($('#cycle-count-detail-message'));
        $('#cycle-count-detail-title').textContent = 'Loading Cycle Count…';
        try {
            const response = await api(`/api/v1/inventory/cycle-counts/${id}`);
            state.currentInventoryCycleCount = response.cycle_count;
            renderInventoryCycleCountDetail();
            $('#inventory-cycle-count-detail-dialog').showModal();
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    async function openAccountingMove(id) {
        if (!can('accounting.read')) return;
        const dialog = $('#accounting-move-dialog');
        $('#accounting-move-title').textContent = 'Loading Journal Entry…';
        $('#accounting-move-summary').replaceChildren(); $('#accounting-move-lines').replaceChildren();
        $('#accounting-move-source').hidden = true; $('#accounting-move-source').onclick = null;
        setFormError('accounting-move-error'); state.currentAccountingMove = null;
        try {
            const response = await api(`/api/v1/accounting/moves/${id}`);
            state.currentAccountingMove = response.move;
            renderAccountingMove();
            dialog.showModal();
        } catch (error) { setFormError('accounting-move-error', error.message); dialog.showModal(); }
    }

    function renderAccountingMove() {
        const move = state.currentAccountingMove;
        if (!move) return;
        $('#accounting-move-title').textContent = `${move.name} · ${move.state}`;
        $('#accounting-move-summary').innerHTML = `<dl class="accounting-move-summary-grid">
            <div><dt>Company</dt><dd>${escapeHtml(move.company_name)}</dd></div>
            <div><dt>Journal</dt><dd>${escapeHtml(move.journal_code)} · ${escapeHtml(move.journal_name)}</dd></div>
            <div><dt>Type / Status</dt><dd>${escapeHtml(move.move_type)} · ${escapeHtml(move.state)}</dd></div>
            <div><dt>Accounting Date</dt><dd>${escapeHtml(move.accounting_date)}</dd></div>
            <div><dt>Reference</dt><dd>${escapeHtml(move.reference || '—')}</dd></div>
            <div><dt>Source</dt><dd>${escapeHtml(move.reference_model)} · ${escapeHtml(move.reference_id)}</dd></div>
            <div><dt>Total Debit</dt><dd>${escapeHtml(formatAccountingAmount(move.total_debit, move.currency_code))}</dd></div>
            <div><dt>Total Credit</dt><dd>${escapeHtml(formatAccountingAmount(move.total_credit, move.currency_code))}</dd></div>
            <div><dt>Currency</dt><dd>${escapeHtml(move.currency_code)}</dd></div>
            <div><dt>Created By / At</dt><dd>${escapeHtml(move.created_by_name)} · ${escapeHtml(move.created_at)}</dd></div>
            <div><dt>Posted By / At</dt><dd>${escapeHtml(move.posted_by_name || '—')} · ${escapeHtml(move.posted_at || '—')}</dd></div>
        </dl>`;
        const body = $('#accounting-move-lines'); body.replaceChildren();
        if (!(move.lines || []).length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 8; cell.className = 'empty-cell'; cell.textContent = 'This journal entry has no lines.'; }
        for (const line of move.lines || []) {
            const row = body.insertRow();
            row.insertCell().textContent = String(line.sequence);
            row.insertCell().textContent = `${line.account_code} · ${line.account_name}`;
            row.insertCell().textContent = line.contact_name || '—';
            row.insertCell().textContent = line.label || '—';
            row.insertCell().textContent = formatAccountingAmount(line.debit, move.currency_code);
            row.insertCell().textContent = formatAccountingAmount(line.credit, move.currency_code);
            row.insertCell().textContent = line.currency_code || move.currency_code;
            row.insertCell().textContent = line.amount_currency === null ? '—' : formatAccountingAmount(line.amount_currency, line.currency_code || move.currency_code);
        }
        const source = $('#accounting-move-source');
        source.hidden = !accountingSourceCanOpen(move);
        if (!source.hidden) source.onclick = () => openAccountingSourceRecord().catch((error) => setFormError('accounting-move-error', error.message));
    }

    function accountingSourceCanOpen(move) {
        if (!move || !Number(move.source_record_id)) return false;
        return ({
            customer_invoice: () => can('invoicing.read'),
            vendor_bill: () => can('purchase.read') && can('invoicing.read'),
            inventory_cycle_count: () => can('inventory.read'),
            inventory_valuation_closing: () => can('inventory.read') && can('accounting.read'),
            manufacturing_order: () => can('manufacturing.read'),
            bank_statement: () => can('accounting.read'),
        }[move.source_record_model]?.() ?? false);
    }

    async function openAccountingSourceRecord() {
        const move = state.currentAccountingMove;
        if (!accountingSourceCanOpen(move)) return;
        const id = Number(move.source_record_id); const companyId = String(move.company_id);
        $('#accounting-move-dialog').close();
        $$('dialog[open]').forEach((dialog) => dialog.close());
        switch (move.source_record_model) {
            case 'customer_invoice':
                $('#invoice-company').value = companyId; selectPage('invoicing');
                await initializeInvoicing(companyId); await openInvoice(id); break;
            case 'vendor_bill':
                $('#purchase-company').value = companyId; selectPage('purchase');
                await initializePurchase(companyId); setPurchaseView('bills'); await openVendorBill(id); break;
            case 'inventory_cycle_count':
                $('#inventory-company').value = companyId; selectPage('inventory');
                await initializeInventory(); selectInventoryView('cycle-counts'); await openInventoryCycleCountDetail(id); break;
            case 'inventory_valuation_closing':
                $('#inventory-company').value = companyId; selectPage('inventory');
                await initializeInventory(); selectInventoryView('valuation'); await openInventoryClosing(id); break;
            case 'manufacturing_order':
                $('#manufacturing-company').value = companyId; selectPage('manufacturing');
                await initializeManufacturing(); await openManufacturingOrderDetail(id); break;
            case 'bank_statement':
                $('#accounting-company').value = companyId; setAccountingView('bank-statements', false); selectPage('accounting');
                await initializeAccounting(companyId); await openBankStatement(id, false, Number(move.source_record_line_id)); break;
        }
    }

    function renderInventoryCycleCountDetail() {
        const count = state.currentInventoryCycleCount;
        if (!count) return;
        const company = state.companies.find((item) => Number(item.id) === Number(count.company_id));
        const countState = count.state === 'counting' ? 'In Progress' : count.state === 'done' ? 'Validated' : 'Cancelled';
        const hideExpected = !count.show_expected_quantity && count.state === 'counting';
        const countedLines = (count.lines || []).filter((line) => line.counted_quantity !== null).length;
        const differenceLines = (count.lines || []).filter((line) => Number(line.difference_quantity || 0) !== 0).length;
        $('#cycle-count-detail-title').textContent = `${count.reference} · ${countState}`;
        $('#cycle-count-summary').innerHTML = `<dl class="cycle-count-summary-grid">
            <div><dt>Company</dt><dd>${escapeHtml(company?.name || count.company_id)}</dd></div>
            <div><dt>Internal Location</dt><dd>${escapeHtml(count.location_name)}</dd></div>
            <div><dt>Status</dt><dd>${escapeHtml(countState)}</dd></div>
            <div><dt>Assigned To</dt><dd>${escapeHtml(count.assigned_to_name || '—')}</dd></div>
            <div><dt>Scheduled Date</dt><dd>${escapeHtml(count.scheduled_at || '—')}</dd></div>
            <div><dt>Show Expected Quantity</dt><dd>${count.show_expected_quantity ? 'Yes' : 'No'}</dd></div>
            <div><dt>Inventory Frequency</dt><dd>${Number(count.inventory_frequency_days || 0)} days</dd></div>
            <div><dt>Last Inventory Date</dt><dd>${escapeHtml(count.last_inventory_date || '—')}</dd></div>
            <div><dt>Next Inventory Date</dt><dd>${escapeHtml(count.next_inventory_date || '—')}</dd></div>
            <div><dt>Positions Counted</dt><dd>${countedLines} / ${(count.lines || []).length}</dd></div>
            <div><dt>Positions with Difference</dt><dd>${differenceLines}</dd></div>
            <div><dt>Created By / At</dt><dd>${escapeHtml(count.created_by_name || '—')} · ${escapeHtml(count.created_at || '—')}</dd></div>
            <div><dt>Validated By / At</dt><dd>${escapeHtml(count.validated_by_name || '—')} · ${escapeHtml(count.validated_at || '—')}</dd></div>
            <div><dt>Accounting Journal Entry</dt><dd>${count.accounting_move_id && can('accounting.read') ? `<button id="cycle-count-open-accounting" class="link-button" type="button">${escapeHtml(count.accounting_move_name || 'Journal Entry')} · ${escapeHtml(count.accounting_move_state || '—')}</button>` : escapeHtml(count.accounting_move_id ? 'Restricted' : 'No Perpetual entry')}</dd></div>
            <div><dt>Last Updated</dt><dd>${escapeHtml(count.updated_at || '—')}</dd></div>
            <div class="wide"><dt>Count Note</dt><dd>${escapeHtml(count.note || '—')}</dd></div>
        </dl>`;
        const openAccounting = $('#cycle-count-open-accounting');
        if (openAccounting) openAccounting.addEventListener('click', () => openAccountingMove(Number(count.accounting_move_id)));
        $('#cycle-count-line-help').textContent = hideExpected
            ? 'Enter the physical Counted Quantity for each position. Expected and reserved quantities are hidden; the server still prevents counting below open reservations.'
            : 'Enter the physical Counted Quantity for each captured position. Reserved stock is shown and cannot be counted below its open reservation.';
        const body = $('#cycle-count-line-rows'); body.replaceChildren();
        if (!(count.lines || []).length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 9; cell.className = 'empty-cell'; cell.textContent = 'No stock positions were captured.'; }
        for (const line of count.lines || []) {
            const row = body.insertRow();
            row.insertCell().textContent = `${line.product_name}${line.internal_reference ? ` · ${line.internal_reference}` : ''}`;
            row.insertCell().textContent = line.tracking === 'serial' ? 'Serial' : line.tracking === 'lot' ? 'Lot' : 'By Quantity';
            row.insertCell().textContent = line.lot_code || '—';
            row.insertCell().textContent = line.expiration_date || '—';
            row.insertCell().textContent = hideExpected ? 'Hidden' : `${line.theoretical_quantity} ${line.uom}`;
            row.insertCell().textContent = hideExpected ? 'Hidden' : `${line.reserved_quantity} ${line.uom}`;
            const countedCell = row.insertCell();
            if (count.state === 'counting' && can('inventory.write')) {
                const wrapper = document.createElement('div'); wrapper.className = 'cycle-count-quantity-edit';
                const input = document.createElement('input'); input.type = 'number'; input.min = hideExpected ? '0' : line.reserved_quantity; input.step = '0.000001';
                input.value = line.counted_quantity ?? ''; input.setAttribute('aria-label', `Counted quantity for ${line.product_name}`);
                const save = document.createElement('button'); save.type = 'button'; save.className = 'button button-small'; save.textContent = 'Save';
                save.addEventListener('click', () => saveInventoryCycleCountLine(Number(line.id), input.value));
                wrapper.append(input, save); countedCell.append(wrapper);
            } else countedCell.textContent = line.counted_quantity === null ? 'Not counted' : `${line.counted_quantity} ${line.uom}`;
            row.insertCell().textContent = hideExpected ? 'Hidden' : line.difference_quantity === null ? '—' : `${line.difference_quantity} ${line.uom}`;
            const adjustment = row.insertCell();
            if (line.adjustment_move_id && can('inventory.read')) {
                const moveButton = document.createElement('button'); moveButton.type = 'button'; moveButton.className = 'link-button';
                moveButton.textContent = `${line.adjustment_reference || 'Stock Move'} · ${line.adjustment_state || '—'} · line ${line.adjustment_move_line_id}`;
                moveButton.addEventListener('click', () => openStockMove(Number(line.adjustment_move_id)));
                adjustment.append(moveButton);
            } else adjustment.textContent = line.adjustment_reference ? `${line.adjustment_reference} · ${line.adjustment_state || ''} · line ${line.adjustment_move_line_id}` : '—';
        }
        const inProgress = count.state === 'counting';
        $('#cycle-count-add-line-form').hidden = !inProgress || !can('inventory.write');
        $('#cycle-count-validate').hidden = !inProgress || !can('inventory.validate');
        $('#cycle-count-cancel').hidden = !inProgress || !can('inventory.write');
        $('#cycle-count-detail-actions-help').textContent = count.state === 'done'
            ? 'Validation applied the stock differences through Inventory and linked any Perpetual adjustment to Accounting.'
            : count.state === 'cancelled' ? 'Cancelled counts are retained for audit.' : 'Stock is rechecked at validation; if it changed, create a fresh count.';
        const products = (state.products || []).filter((product) => product.product_type === 'goods' && product.active !== 0 && product.active !== '0');
        fillSelect($('#cycle-count-line-product'), products, { placeholder: 'Select Goods product', label: (product) => `${product.name}${product.internal_reference ? ` · ${product.internal_reference}` : ''}` });
    }

    async function reloadInventoryCycleCountDetail() {
        if (!state.currentInventoryCycleCount) return;
        const response = await api(`/api/v1/inventory/cycle-counts/${state.currentInventoryCycleCount.id}`);
        state.currentInventoryCycleCount = response.cycle_count;
        renderInventoryCycleCountDetail();
        await loadInventoryCycleCounts();
    }

    async function saveInventoryCycleCountLine(lineId, countedQuantity) {
        setFormError('cycle-count-detail-error');
        try {
            const count = state.currentInventoryCycleCount;
            await api(`/api/v1/inventory/cycle-counts/${count.id}/lines/${lineId}`, { method: 'PATCH', body: JSON.stringify({ counted_quantity: countedQuantity }) });
            await reloadInventoryCycleCountDetail();
        } catch (error) { setFormError('cycle-count-detail-error', error.message); }
    }

    async function addInventoryCycleCountLine(event) {
        event.preventDefault();
        const form = event.currentTarget; if (!form.reportValidity()) return;
        setFormError('cycle-count-detail-error');
        try {
            const count = state.currentInventoryCycleCount;
            await api(`/api/v1/inventory/cycle-counts/${count.id}/lines`, { method: 'POST', body: JSON.stringify({
                product_id: Number(form.elements.product_id.value), lot_code: form.elements.lot_code.value.trim() || null,
                expiration_date: form.elements.expiration_date.value || null, counted_quantity: form.elements.counted_quantity.value,
            }) });
            form.reset(); await reloadInventoryCycleCountDetail();
        } catch (error) { setFormError('cycle-count-detail-error', error.message); }
    }

    async function validateInventoryCycleCount() {
        const count = state.currentInventoryCycleCount;
        if (!count || !window.confirm(`Validate ${count.reference}? This applies every stock difference and may post a Perpetual accounting entry.`)) return;
        setFormError('cycle-count-detail-error');
        try {
            await api(`/api/v1/inventory/cycle-counts/${count.id}/validate`, { method: 'POST', body: JSON.stringify({}) });
            await reloadInventoryCycleCountDetail();
            await Promise.all([loadProducts(), loadStockLevels(), loadStockMoves(), loadInventoryValuation()]);
            showMessage($('#inventory-message'), `${count.reference} validated; stock and configured accounting entries were updated.`);
        } catch (error) { setFormError('cycle-count-detail-error', error.message); }
    }

    async function cancelInventoryCycleCount() {
        const count = state.currentInventoryCycleCount;
        if (!count || !window.confirm(`Cancel ${count.reference}? The count will remain in the audit history.`)) return;
        setFormError('cycle-count-detail-error');
        try {
            await api(`/api/v1/inventory/cycle-counts/${count.id}/cancel`, { method: 'POST', body: JSON.stringify({}) });
            await reloadInventoryCycleCountDetail();
        } catch (error) { setFormError('cycle-count-detail-error', error.message); }
    }

    async function loadInventoryValuation(append = false) {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) {
            state.inventoryValuation = []; state.inventoryValuationTotal = 0; state.inventoryClosingReport = null; renderInventoryValuation(); renderInventoryClosing(); return;
        }
        const offset = append ? state.inventoryValuation.length : 0;
        const query = new URLSearchParams({ company_id: companyId, q: $('#valuation-search').value.trim(), limit: '100', offset: String(offset) });
        const payload = await api('/api/v1/inventory/valuation?' + query);
        state.inventoryValuation = append ? state.inventoryValuation.concat(payload.records || []) : (payload.records || []);
        state.inventoryValuationTotal = Number(payload.total || 0);
        renderInventoryValuation();
        await loadInventoryClosingReport();
    }

    function renderInventoryValuation() {
        const body = $('#inventory-valuation-rows'); body.replaceChildren();
        if (!state.inventoryValuation.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 9; cell.className = 'empty-cell';
            cell.textContent = state.inventoryValuationTotal ? 'No valued products match this search.' : 'No inventory valuation yet. Validate an incoming receipt to establish the opening value.';
        }
        for (const item of state.inventoryValuation) {
            const row = body.insertRow();
            if (item.quantity_discrepancy !== '0.000000') row.classList.add('is-discrepant');
            row.insertCell().textContent = item.internal_reference || '—';
            row.insertCell().textContent = item.product_name;
            row.insertCell().textContent = item.category_name || '—';
            row.insertCell().textContent = item.costing_method || '—';
            row.insertCell().textContent = `${item.physical_quantity} ${item.uom}`;
            row.insertCell().textContent = `${item.valuation_quantity} ${item.uom}`;
            row.insertCell().textContent = `${item.quantity_discrepancy} ${item.uom}`;
            row.insertCell().textContent = formatCurrencyAmount(item.unit_value, item.currency_code);
            row.insertCell().textContent = formatCurrencyAmount(item.valuation_value, item.currency_code);
        }
        $('#inventory-valuation-total').textContent = `${state.inventoryValuationTotal} valued product${state.inventoryValuationTotal === 1 ? '' : 's'}`;
        $('#inventory-valuation-more').hidden = state.inventoryValuation.length >= state.inventoryValuationTotal;
    }

    async function loadInventoryClosingReport(append = false) {
        const companyId = $('#inventory-company').value;
        if (!companyId) { state.inventoryClosingReport = null; renderInventoryClosing(); return; }
        const offset = append ? (state.inventoryClosingReport?.closings?.length || 0) : 0;
        const query = new URLSearchParams({ company_id: companyId, as_of_date: $('#inventory-closing-date').value || accountingToday(), offset: String(offset) });
        try {
            const payload = await api('/api/v1/inventory/valuation-closing?' + query);
            const incoming = payload.report;
            state.inventoryClosingReport = append && state.inventoryClosingReport
                ? { ...incoming, closings: state.inventoryClosingReport.closings.concat(incoming.closings || []) }
                : incoming;
            renderInventoryClosing();
        } catch (error) {
            state.inventoryClosingReport = null;
            renderInventoryClosing(error.message);
        }
    }

    function renderInventoryClosing(error = '') {
        const report = state.inventoryClosingReport;
        const readiness = $('#inventory-closing-readiness');
        const summary = $('#inventory-closing-summary');
        const groupWrap = $('#inventory-closing-groups-wrap');
        const groupsBody = $('#inventory-closing-groups');
        const historyBody = $('#inventory-closing-history');
        const canReadClosing = can('inventory.read') && can('accounting.read');
        const canGenerate = canReadClosing && can('accounting.write');
        $('#inventory-closing-date').disabled = !can('inventory.read');
        $('#inventory-closing-generate').hidden = !canGenerate;
        readiness.hidden = !error && Boolean(report);
        readiness.classList.toggle('error', Boolean(error));
        readiness.textContent = error || (report ? '' : 'Select a company to review inventory valuation.');
        summary.hidden = !report;
        groupWrap.hidden = !report;
        if (report) {
            const difference = Number(report.difference_value || 0);
            const match = Math.abs(difference) < (10 ** -(Number(report.decimal_places || 2)) / 2);
            $('#inventory-closing-generate').disabled = !canGenerate || match;
            $('#inventory-closing-generate').textContent = match ? 'No Adjustment Needed' : 'Generate Draft Entry';
            summary.replaceChildren();
            for (const [label, value] of [
                ['As of Date', report.as_of_date],
                ['Physical Stock Value', formatCurrencyAmount(report.physical_value, report.currency_code)],
                ['Posted GL Value', formatCurrencyAmount(report.accounting_value, report.currency_code)],
                ['Difference', formatCurrencyAmount(report.difference_value, report.currency_code)],
            ]) {
                const item = document.createElement('div'); item.className = 'inventory-closing-summary-item';
                const title = document.createElement('span'); title.textContent = label;
                const amount = document.createElement('strong'); amount.textContent = value;
                item.append(title, amount); summary.append(item);
            }
            groupsBody.replaceChildren();
            for (const group of report.groups || []) {
                const row = groupsBody.insertRow();
                row.insertCell().textContent = `${group.valuation_account_code} · ${group.valuation_account_name}`;
                row.insertCell().textContent = `${group.variation_account_code} · ${group.variation_account_name}`;
                row.insertCell().textContent = formatCurrencyAmount(group.physical_value, report.currency_code);
                row.insertCell().textContent = formatCurrencyAmount(group.accounting_value, report.currency_code);
                row.insertCell().textContent = formatCurrencyAmount(group.difference_value, report.currency_code);
            }
        } else {
            $('#inventory-closing-generate').disabled = true;
            $('#inventory-closing-generate').textContent = 'Generate Draft Entry';
            groupsBody.replaceChildren();
        }
        const closings = report?.closings || [];
        historyBody.replaceChildren();
        if (!closings.length) {
            const row = historyBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 8; cell.className = 'empty-cell';
            cell.textContent = error ? 'Closing history is unavailable until Inventory Accounting setup is complete.' : 'No inventory valuation closing entries yet.';
        }
        for (const closing of closings) {
            const row = historyBody.insertRow();
            row.insertCell().textContent = closing.name;
            row.insertCell().textContent = closing.as_of_date;
            row.insertCell().textContent = formatCurrencyAmount(closing.physical_value, closing.currency_code);
            row.insertCell().textContent = formatCurrencyAmount(closing.accounting_value, closing.currency_code);
            row.insertCell().textContent = formatCurrencyAmount(closing.difference_value, closing.currency_code);
            const status = row.insertCell(); const pill = document.createElement('span'); pill.className = `status-pill${closing.state === 'posted' ? ' done' : ''}`; pill.textContent = closing.state; status.append(pill);
            const entryCell = row.insertCell(); entryCell.append(accountingMoveLink(closing.accounting_move_id, closing.accounting_move_name, closing.accounting_move_state));
            const actionCell = row.insertCell(); const action = document.createElement('button'); action.className = 'button button-small'; action.type = 'button'; action.textContent = 'Review'; action.addEventListener('click', () => openInventoryClosing(Number(closing.id)).catch((loadError) => showMessage($('#inventory-message'), loadError.message, true))); actionCell.append(action);
        }
        $('#inventory-closing-history-total').textContent = `${Number(report?.closings_total || 0)} closing entr${Number(report?.closings_total || 0) === 1 ? 'y' : 'ies'}`;
        $('#inventory-closing-history-more').hidden = !report || closings.length >= Number(report.closings_total || 0);
    }

    async function generateInventoryClosing() {
        if (!can('accounting.write')) return;
        const companyId = $('#inventory-company').value;
        if (!companyId) { showMessage($('#inventory-message'), 'Select a company before generating an inventory closing.', true); return; }
        const button = $('#inventory-closing-generate'); button.disabled = true;
        try {
            const result = await api('/api/v1/inventory/valuation-closings', { method: 'POST', body: JSON.stringify({ company_id: Number(companyId), as_of_date: $('#inventory-closing-date').value }) });
            await openInventoryClosing(Number(result.closing.id), result.closing);
            await loadInventoryClosingReport();
            showMessage($('#inventory-message'), result.closing.state === 'posted' ? 'This date already has a posted inventory valuation closing.' : 'Draft journal entry created. Review all account lines before posting.');
        } catch (error) {
            showMessage($('#inventory-message'), error.message, true);
        } finally { button.disabled = false; }
    }

    async function openInventoryClosing(id, loaded = null) {
        const closing = loaded || (await api(`/api/v1/inventory/valuation-closings/${id}`)).closing;
        state.currentInventoryClosing = closing;
        $('#inventory-closing-title').textContent = `${closing.name} · Review Inventory Valuation Closing`;
        const summary = $('#inventory-closing-detail-summary'); summary.replaceChildren();
        for (const [label, value] of [
            ['Status', closing.state], ['As of Date', closing.as_of_date],
            ['Physical Stock Value', formatCurrencyAmount(closing.physical_value, closing.currency_code)],
            ['Posted GL Value', formatCurrencyAmount(closing.accounting_value, closing.currency_code)],
            ['Difference', formatCurrencyAmount(closing.difference_value, closing.currency_code)],
            ['Journal', `${closing.journal_code} · ${closing.journal_name}`],
        ]) {
            const item = document.createElement('div'); item.className = 'inventory-closing-summary-item'; const title = document.createElement('span'); title.textContent = label; const valueNode = document.createElement('strong'); valueNode.textContent = value; item.append(title, valueNode); summary.append(item);
        }
        const body = $('#inventory-closing-detail-lines'); body.replaceChildren();
        for (const line of closing.lines || []) {
            const row = body.insertRow();
            row.insertCell().textContent = `${line.valuation_account_code} · ${line.valuation_account_name}`;
            row.insertCell().textContent = `${line.variation_account_code} · ${line.variation_account_name}`;
            row.insertCell().textContent = formatCurrencyAmount(line.physical_value, closing.currency_code);
            row.insertCell().textContent = formatCurrencyAmount(line.accounting_value, closing.currency_code);
            row.insertCell().textContent = formatCurrencyAmount(line.difference_value, closing.currency_code);
        }
        const draft = closing.state === 'draft';
        const canPost = can('inventory.read') && can('accounting.read') && can('accounting.post');
        $('#inventory-closing-post').hidden = !draft || !canPost;
        $('#inventory-closing-discard').hidden = !draft || !can('inventory.read') || !can('accounting.read') || !can('accounting.write');
        $('#inventory-closing-post').disabled = !draft || !canPost;
        $('#inventory-closing-detail-error').textContent = '';
        const closingNote = $('#inventory-closing-detail-note'); closingNote.replaceChildren();
        if (draft) closingNote.textContent = 'Posting updates the company General Ledger. The system will recheck stock, account balances, and every journal line immediately before posting.';
        else if (closing.state === 'posted') closingNote.append(document.createTextNode(`Posted ${closing.posted_at ? `on ${closing.posted_at}` : ''}. General Ledger entry: `), accountingMoveLink(closing.accounting_move_id, closing.accounting_move_name, closing.accounting_move_state), document.createTextNode('.'));
        else closingNote.textContent = 'This draft was discarded and cannot be posted.';
        $('#inventory-closing-dialog').showModal();
    }

    async function postInventoryClosing() {
        const closing = state.currentInventoryClosing;
        if (!closing || closing.state !== 'draft' || !can('accounting.post')) return;
        const button = $('#inventory-closing-post'); button.disabled = true;
        try {
            const result = await api(`/api/v1/inventory/valuation-closings/${closing.id}/post`, { method: 'POST', body: JSON.stringify({}) });
            state.currentInventoryClosing = result.closing;
            $('#inventory-closing-dialog').close();
            await loadInventoryClosingReport();
            showMessage($('#inventory-message'), `${result.closing.name} posted to the General Ledger.`);
        } catch (error) {
            $('#inventory-closing-detail-error').textContent = error.message;
        } finally { button.disabled = false; }
    }

    async function discardInventoryClosing() {
        const closing = state.currentInventoryClosing;
        if (!closing || closing.state !== 'draft' || !can('accounting.write')) return;
        if (!window.confirm(`Discard the draft inventory closing ${closing.name}? Its draft journal entry will also be removed.`)) return;
        const button = $('#inventory-closing-discard'); button.disabled = true;
        try {
            const result = await api(`/api/v1/inventory/valuation-closings/${closing.id}/discard`, { method: 'POST', body: JSON.stringify({}) });
            state.currentInventoryClosing = result.closing;
            $('#inventory-closing-dialog').close();
            await loadInventoryClosingReport();
            showMessage($('#inventory-message'), `${result.closing.name} draft discarded.`);
        } catch (error) {
            $('#inventory-closing-detail-error').textContent = error.message;
        } finally { button.disabled = false; }
    }

    async function loadInventoryAccounting() {
        showMessage($('#inventory-message'));
        const companyId = $('#inventory-company').value;
        if (!companyId) {
            state.inventoryAccounting = null;
            renderInventoryAccounting();
            return;
        }
        const result = await api('/api/v1/inventory/accounting-settings?company_id=' + encodeURIComponent(companyId));
        state.inventoryAccounting = result.accounting;
        renderInventoryAccounting();
    }

    function renderInventoryAccounting() {
        const payload = state.inventoryAccounting;
        const settings = payload?.settings || {};
        const accounts = payload?.accounts || [];
        const journals = payload?.journals || [];
        const types = {
            valuation: ['asset_current','asset_non_current'],
            variation: ['asset_current','expense','expense_other','expense_direct_cost'],
            expense: ['expense','expense_other','expense_direct_cost'],
            loss: ['expense','expense_other','expense_direct_cost'],
            production: ['asset_current','expense','expense_direct_cost'],
        };
        const accountLabel = (account) => `${account.code} · ${account.name}`;
        const select = (choices, selected, placeholder, label) => {
            const control = document.createElement('select');
            control.setAttribute('aria-label', label);
            control.add(new Option(placeholder, ''));
            for (const row of choices) control.add(new Option(row.label || row.name, String(row.id)));
            control.value = selected === null || selected === undefined ? '' : String(selected);
            control.dataset.field = label;
            control.disabled = !can('accounting.write');
            return control;
        };
        const accountsOf = (allowed) => accounts.filter((account) => allowed.includes(account.account_type)).map((account) => ({ id: account.id, name: accountLabel(account), label: accountLabel(account) }));
        $('#inventory-accounting-standard').value = settings.accounting_standard || '';
        $('#inventory-valuation-method').value = settings.valuation_method || 'periodic';
        $('#inventory-closing-frequency').value = settings.closing_frequency || 'manual';
        $('#inventory-costing-method').value = settings.costing_method || 'fifo';
        fillSelect($('#inventory-valuation-account'), accountsOf(types.valuation), { placeholder: 'Select a stock valuation account', keepValue: settings.valuation_account_id || '' });
        fillSelect($('#inventory-variation-account'), accountsOf(types.variation), { placeholder: 'Select a stock variation account', keepValue: settings.variation_account_id || '' });
        fillSelect($('#inventory-expense-adjustment-account'), accountsOf(types.expense), { placeholder: 'Select an expense adjustment account', keepValue: settings.expense_adjustment_account_id || '' });
        fillSelect($('#inventory-valuation-journal'), journals, { placeholder: 'Select a general journal', label: (journal) => `${journal.code} · ${journal.name}`, keepValue: settings.journal_id || '' });
        ['#inventory-accounting-standard','#inventory-valuation-method','#inventory-closing-frequency','#inventory-costing-method','#inventory-valuation-account','#inventory-variation-account','#inventory-expense-adjustment-account','#inventory-valuation-journal'].forEach((selector) => { $(selector).disabled = !can('accounting.write'); });

        const categoryBody = $('#inventory-category-accounting-rows');
        categoryBody.replaceChildren();
        for (const category of payload?.categories || []) {
            const row = categoryBody.insertRow();
            const name = row.insertCell(); name.textContent = category.name;
            const cost = row.insertCell(); cost.append(select([{id:'standard',name:'Standard Price'},{id:'fifo',name:'FIFO'},{id:'average',name:'Average Cost'}], category.costing_method_override, 'Use company default', 'Category Cost Method')); cost.firstChild.dataset.categoryId = String(category.id);
            const method = row.insertCell(); method.append(select([{id:'periodic',name:'Periodic (at closing)'},{id:'perpetual',name:'Perpetual (at invoicing)'}], category.valuation_method_override, 'Use company default', 'Category Inventory Valuation')); method.firstChild.dataset.categoryId = String(category.id);
            const valuationAccount = row.insertCell(); valuationAccount.append(select(accountsOf(types.valuation), category.valuation_account_id, 'Use company default', 'Category Valuation Account')); valuationAccount.firstChild.dataset.categoryId = String(category.id);
            const variationAccount = row.insertCell(); variationAccount.append(select(accountsOf(types.variation), category.variation_account_id, 'Use company default', 'Category Variation Account')); variationAccount.firstChild.dataset.categoryId = String(category.id);
            const expenseAccount = row.insertCell(); expenseAccount.append(select(accountsOf(types.expense), category.expense_account_id, 'Use product/default', 'Category Expense Account')); expenseAccount.firstChild.dataset.categoryId = String(category.id);
            const expenseAdjustmentAccount = row.insertCell(); expenseAdjustmentAccount.append(select(accountsOf(types.expense), category.expense_adjustment_account_id, 'Use company default', 'Category Expense Adjustment Account')); expenseAdjustmentAccount.firstChild.dataset.categoryId = String(category.id);
            const priceDifferenceAccount = row.insertCell(); priceDifferenceAccount.append(select(accountsOf(types.expense), category.price_difference_account_id, 'Not configured', 'Category Price Difference Account')); priceDifferenceAccount.firstChild.dataset.categoryId = String(category.id);
        }
        if (!(payload?.categories || []).length) { const row = categoryBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 8; cell.className = 'empty-cell'; cell.textContent = companyIdForInventoryAccounting() ? 'No active product categories are available for this company.' : 'Select a company to load categories.'; }

        const locationBody = $('#inventory-location-accounting-rows');
        locationBody.replaceChildren();
        for (const location of payload?.locations || []) {
            const row = locationBody.insertRow();
            row.dataset.locationId = String(location.id);
            row.insertCell().textContent = location.complete_name || location.name;
            row.insertCell().textContent = location.location_usage === 'inventory' ? 'Inventory Loss' : 'Production';
            const lossCell = row.insertCell();
            if (location.location_usage === 'inventory') {
                const control = select(accountsOf(types.loss), location.inventory_loss_account_id, 'Select loss account', 'Inventory Loss Account');
                control.dataset.locationId = String(location.id); lossCell.append(control);
            } else lossCell.textContent = '—';
            const productionCell = row.insertCell();
            if (location.location_usage === 'production') {
                const control = select(accountsOf(types.production), location.cost_of_production_account_id, 'Select production account', 'Cost of Production Account');
                control.dataset.locationId = String(location.id); productionCell.append(control);
            } else productionCell.textContent = '—';
        }
        if (!(payload?.locations || []).length) { const row = locationBody.insertRow(); const cell = row.insertCell(); cell.colSpan = 4; cell.className = 'empty-cell'; cell.textContent = companyIdForInventoryAccounting() ? 'No active Inventory Loss or Production locations are configured.' : 'Select a company to load locations.'; }
        const ready = Boolean(settings.ready);
        showMessage($('#inventory-accounting-readiness'), ready
            ? 'Required valuation accounts, journal, and accounting standard are configured. Bills, invoices, valuation closing, and eligible Anglo-Saxon Perpetual Manufacturing Orders use the configured accounting links.'
            : 'Setup is incomplete. Select an accounting standard, Stock Valuation Account, Variation Account, and active General Journal before posting valuation entries.', false);
    }

    function companyIdForInventoryAccounting() { return Boolean($('#inventory-company').value); }

    async function saveInventoryAccounting() {
        if (!can('accounting.write')) return;
        const companyId = $('#inventory-company').value;
        if (!companyId) { showMessage($('#inventory-message'), 'Select a company before saving inventory accounting setup.', true); return; }
        const categories = [...$('#inventory-category-accounting-rows').querySelectorAll('select[data-category-id]')];
        const categoryMap = new Map();
        for (const control of categories) {
            const id = control.dataset.categoryId;
            if (!categoryMap.has(id)) categoryMap.set(id, { id: Number(id) });
            const row = categoryMap.get(id);
            const field = control.getAttribute('aria-label');
            const map = { 'Category Cost Method':'costing_method','Category Inventory Valuation':'valuation_method','Category Valuation Account':'valuation_account_id','Category Variation Account':'variation_account_id','Category Expense Account':'expense_account_id','Category Expense Adjustment Account':'expense_adjustment_account_id','Category Price Difference Account':'price_difference_account_id' };
            row[map[field]] = control.value || null;
        }
        const locations = [...$('#inventory-location-accounting-rows').querySelectorAll('select[data-location-id]')];
        const locationMap = new Map(payloadLocationsWithoutControls().map((row) => [String(row.id), row]));
        for (const control of locations) {
            const id = control.dataset.locationId;
            const row = locationMap.get(id);
            if (control.getAttribute('aria-label') === 'Inventory Loss Account') row.inventory_loss_account_id = control.value || null;
            if (control.getAttribute('aria-label') === 'Cost of Production Account') row.cost_of_production_account_id = control.value || null;
        }
        const body = {
            company_id: Number(companyId),
            settings: {
                accounting_standard: $('#inventory-accounting-standard').value || null,
                valuation_method: $('#inventory-valuation-method').value,
                closing_frequency: $('#inventory-closing-frequency').value,
                costing_method: $('#inventory-costing-method').value,
                valuation_account_id: $('#inventory-valuation-account').value || null,
                variation_account_id: $('#inventory-variation-account').value || null,
                expense_adjustment_account_id: $('#inventory-expense-adjustment-account').value || null,
                journal_id: $('#inventory-valuation-journal').value || null,
            },
            categories: [...categoryMap.values()],
            locations: [...locationMap.values()],
        };
        try {
            const result = await api('/api/v1/inventory/accounting-settings', { method: 'PUT', body: JSON.stringify(body) });
            state.inventoryAccounting = result.accounting;
            renderInventoryAccounting();
            showMessage($('#inventory-message'), 'Inventory valuation accounting setup saved.');
        } catch (error) { showMessage($('#inventory-message'), error.message, true); }
    }

    function payloadLocationsWithoutControls() {
        const rows = state.inventoryAccounting?.locations || [];
        return rows.map((location) => ({
            id: Number(location.id),
            inventory_loss_account_id: location.location_usage === 'inventory' ? null : location.inventory_loss_account_id || null,
            cost_of_production_account_id: location.location_usage === 'production' ? null : location.cost_of_production_account_id || null,
        }));
    }

    function renderStockLevels() {
        const body = $('#stock-level-rows'); body.replaceChildren();
        if (!state.stockLevels.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 8; cell.className = 'empty-cell';
            cell.textContent = 'No on-hand quantities yet. Validate an incoming receipt or inventory adjustment to establish stock.';
        }
        for (const quant of state.stockLevels) {
            const row = body.insertRow();
            row.insertCell().textContent = quant.internal_reference || '—'; row.insertCell().textContent = quant.product_name;
            row.insertCell().textContent = quant.location; row.insertCell().textContent = quant.lot_code || '—';
            row.insertCell().textContent = quant.quantity; row.insertCell().textContent = quant.reserved_quantity;
            row.insertCell().textContent = quant.available_quantity; row.insertCell().textContent = quant.uom;
        }
        $('#stock-level-total').textContent = `${state.stockLevels.length} stock position${state.stockLevels.length === 1 ? '' : 's'}`;
    }

    function fillCompanyFilters() {
        const filter = $('#contact-company'); const selected = filter.value;
        fillSelect(filter, state.companies, { placeholder: 'All allowed companies' });
        filter.value = selected;
        fillSelect($('#contact-company-select'), state.companies, { placeholder: 'Shared contact' });
    }
    function renderContacts() {
        const body = $('#contact-rows'); body.replaceChildren();
        if (!state.contacts.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 7; cell.className = 'empty-cell';
            cell.textContent = state.totalContacts ? 'No contacts match these filters.' : 'No contacts yet. Create a new contact to begin.';
        }
        for (const contact of state.contacts) {
            const row = body.insertRow();
            const nameCell = row.insertCell();
            const open = document.createElement('button'); open.type = 'button'; open.className = 'contact-link';
            open.textContent = contact.name; open.addEventListener('click', () => openContact(Number(contact.id)));
            nameCell.append(open);
            row.insertCell().textContent = state.companies.find((company) => Number(company.id) === Number(contact.company_id))?.name || 'Shared';
            row.insertCell().textContent = contact.email || '—'; row.insertCell().textContent = contact.phone || '—';
            const roles = row.insertCell();
            for (const role of ['customer', 'supplier', 'employee']) if (Number(contact[role])) {
                const pill = document.createElement('span'); pill.className = 'role-pill'; pill.textContent = role[0].toUpperCase() + role.slice(1); roles.append(pill);
            }
            const status = row.insertCell(); const statusPill = document.createElement('span');
            statusPill.className = `status-pill ${Number(contact.active) ? 'done' : 'cancelled'}`;
            statusPill.textContent = Number(contact.active) ? 'Active' : 'Archived'; status.append(statusPill);
            const action = row.insertCell();
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Open';
            edit.disabled = !can('contacts.read'); edit.addEventListener('click', () => openContact(Number(contact.id))); action.append(edit);
            if (can('contacts.archive')) {
                const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'button button-small';
                const nextActive = !Number(contact.active); toggle.textContent = nextActive ? 'Restore' : 'Archive';
                toggle.addEventListener('click', async () => {
                    if (!nextActive && !window.confirm(`Archive ${contact.name}? Historical documents will remain linked.`)) return;
                    toggle.disabled = true;
                    try {
                        await api(`/api/v1/contacts/${Number(contact.id)}/status`, { method: 'PATCH', body: JSON.stringify({ active: nextActive }) });
                        await loadContacts();
                        showMessage($('#contacts-message'), nextActive ? 'Contact restored.' : 'Contact archived. Historical documents remain linked.');
                    } catch (error) {
                        toggle.disabled = false; showMessage($('#contacts-message'), error.message, true);
                    }
                }); action.append(toggle);
            }
        }
        $('#contact-total').textContent = `${state.totalContacts} record${state.totalContacts === 1 ? '' : 's'}`;
    }

    async function openContact(id = null, prefill = {}) {
        if (id && !can('contacts.read')) return;
        if (!id && !can('contact_master_data.create')) return;
        const creationOnly = !can('contacts.read');
        const dialog = $('#contact-dialog'); const form = $('#contact-form');
        clearForm(form); form.dataset.id = ''; state.currentContact = null;
        $('#contact-dialog-title').textContent = id ? 'Loading Contact…' : 'New Contact';
        setFormError('contact-form-error'); $('#contact-save-state').textContent = '';
        switchTabs(dialog, 'contact-sales');
        try {
            const detail = id ? (await api(`/api/v1/contacts/${id}`)).contact : null;
            state.currentContact = detail;
            $('#contact-crm-smart-panel').hidden = true;
            $('#contact-crm-smart-panel').replaceChildren();
            $('#contact-chatter').hidden = true;
            $('#contact-chatter-timeline').replaceChildren();
            $('#contact-message-composer').hidden = true;
            $('#contact-activity-composer').hidden = true;
            showMessage($('#contact-chatter-error'), '');
            const selectedCompanyId = detail?.company_id || prefill.companyId || (creationOnly ? $('#contact-create-company').value : $('#contact-company').value) || '';
            await loadContactOptions(selectedCompanyId, creationOnly);
            if (!creationOnly) fillCompanyFilters();
            if (detail) {
                form.dataset.id = String(detail.id); $('#contact-dialog-title').textContent = detail.name;
                for (const control of $$('[name]', form)) {
                    if (control.name === 'tags_text') control.value = (detail.tags || []).map((tag) => tag.name).join(', ');
                    else if (!['addresses', 'bank_accounts', 'ubo_owners'].includes(control.name)) setControl(form, control.name, detail[control.name]);
                }
                renderAddresses(detail.addresses || []); renderBankAccounts(detail.bank_accounts || []); renderUboOwners(detail.ubo_owners || []);
            } else {
                $('#contact-dialog-title').textContent = 'New Contact';
                if (state.lookup.company_id || selectedCompanyId) setControl(form, 'company_id', state.lookup.company_id || selectedCompanyId);
            }
            updateUboControls();
            if (detail && (can('crm.read') || can('sales.read') || can('sales.write') || can('purchase.read') || can('purchase.write') || can('invoicing.read') || can('accounting.read') || can('todo.read') || can('appointments.read') || can('contracts.read'))) await loadContactCrmLinks(detail, selectedCompanyId);
            setContactFormEditable(detail ? can('contacts.write') : Boolean(state.lookup.contact_create_access));
            updateUboControls();
            configureContactChatter();
            dialog.showModal();
            if (detail && can('contacts.read')) loadContactChatter(detail, selectedCompanyId).catch((error) => showMessage($('#contact-chatter-error'), error.message, true));
        } catch (error) { setFormError('contact-form-error', error.message); dialog.showModal(); }
    }

    function contactSmartGroup(panel, title, value, key = '') {
        const group = document.createElement('section'); group.className = 'contact-smart-group';
        const toolbar = panel.querySelector('.contact-smart-panel-buttons');
        const details = panel.querySelector('.contact-smart-details');
        const index = details.querySelectorAll(':scope > .contact-smart-group').length;
        const contentId = `contact-smart-content-${index + 1}`;
        toolbar.insertAdjacentHTML('beforeend', window.ERPSharedSmartPanel.button({
            iconHtml: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h9l5 5v13H5zM14 3v5h5M8 12h8M8 16h8"/></svg>',
            label: title, value, className: 'contact-smart-panel-button', ariaLabel: `${title}: ${value}`,
            attributes: { 'data-contact-smart-section': contentId, 'data-contact-smart-key': key, 'aria-controls': contentId },
        }));
        const toggle = toolbar.lastElementChild;
        const content = document.createElement('div'); content.className = 'contact-smart-group-content';
        content.id = contentId; content.hidden = index !== 0; group.hidden = index !== 0;
        toggle?.setAttribute('aria-expanded', String(index === 0));
        toggle?.addEventListener('click', () => {
            const shouldOpen = group.hidden;
            details.querySelectorAll('.contact-smart-group').forEach((section) => {
                section.hidden = true;
                section.querySelector('.contact-smart-group-content')?.setAttribute('hidden', '');
            });
            toolbar.querySelectorAll('.contact-smart-panel-button').forEach((button) => { button.setAttribute('aria-expanded', 'false'); });
            group.hidden = !shouldOpen; content.hidden = !shouldOpen; toggle.setAttribute('aria-expanded', String(shouldOpen));
        });
        group.append(content); details.append(group); return content;
    }

    function appendContactSmartAction(panel, button) {
        const groups = panel.querySelectorAll('.contact-smart-group');
        const lastGroup = groups[groups.length - 1];
        (lastGroup?.querySelector('.contact-smart-group-content') || panel).append(button);
    }

    async function appendContactSmartSection(panel, contact, companyId, section) {
        const limit = 100;
        const params = new URLSearchParams({ company_id: companyId, partner_contact_id: String(contact.id), state: 'all', limit: String(limit), offset: '0' });
        const endpoint = `${section.endpoint}?${params}`;
        const first = await api(endpoint); const records = first.records || []; const total = Number(first.total ?? records.length);
        const group = contactSmartGroup(panel, section.title, `${total} ${section.noun}${total === 1 ? '' : 's'}`);
        const links = document.createElement('div'); links.className = 'contact-smart-links';
        const append = (rows) => { for (const record of rows) { const button = document.createElement('button'); button.type = 'button'; button.className = 'text-link'; button.textContent = section.label(record); button.addEventListener('click', () => section.open(record)); links.append(button); } };
        append(records);
        if (!total) { const empty = document.createElement('span'); empty.className = 'help-text'; empty.textContent = section.empty; links.append(empty); }
        group.append(links);
        let offset = records.length;
        if (offset < total) {
            const more = document.createElement('button'); more.type = 'button'; more.className = 'button button-small'; more.textContent = `Load more (${total - offset} remaining)`;
            more.addEventListener('click', async () => {
                more.disabled = true; more.textContent = 'Loading…';
                try {
                    params.set('offset', String(offset));
                    const page = await api(`${section.endpoint}?${params}`); const next = page.records || [];
                    append(next); offset += next.length;
                    if (!next.length || offset >= total) more.remove();
                    else { more.disabled = false; more.textContent = `Load more (${total - offset} remaining)`; }
                } catch (error) { more.disabled = false; more.textContent = 'Retry loading more'; more.title = error.message; }
            });
            group.append(more);
        }
    }

    async function loadContactCrmLinks(contact, companyId) {
        const panel = $('#contact-crm-smart-panel');
        if (!contact?.id) { panel.hidden = true; return; }
        panel.replaceChildren(); panel.hidden = false;
        panel.insertAdjacentHTML('beforeend', window.ERPSharedSmartPanel.render({
            className: 'erp-contact-related-smartbar', itemsClass: 'contact-smart-panel-buttons', ariaLabel: 'Contact smart buttons', items: [],
        }));
        const details = document.createElement('div'); details.className = 'contact-smart-details'; panel.append(details);
        const selectedCompany = String(companyId || contact.company_id || state.defaultCompanyId || '');
        if (can('todo.read') && can('contacts.read')) await loadContactTodoLinks(contact, selectedCompany);
        if (can('crm.read')) {
            await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/crm/leads', title: 'CRM', noun: 'lead / opportunity record', empty: 'No CRM records linked yet.', label: (lead) => `${lead.name} · ${lead.record_type} · ${lead.state}`, open: (lead) => openCrmRecord(Number(lead.id)) });
            if (can('crm.write')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'Add Lead'; add.addEventListener('click', () => openCrmRecord(null, { companyId: selectedCompany, contactId: Number(contact.id) })); appendContactSmartAction(panel, add); }
        }
        if (can('contracts.read')) {
            await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/contracts', title: 'Contracts', noun: 'agreement', empty: 'No Contracts linked yet.', label: (contract) => `${contract.name} · ${contract.contract_type.replaceAll('_', ' ')} · ${contract.status}`, open: (contract) => { $('#contact-dialog').close(); $('#contracts-company').value = String(contract.company_id); selectPage('contracts'); openContract(Number(contract.id)); } });
            if (can('contracts.write')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New Contract'; add.addEventListener('click', () => { $('#contact-dialog').close(); selectPage('contracts'); openContract(null, { companyId: selectedCompany, contactId: Number(contact.id), type: Number(contact.customer) ? 'customer' : Number(contact.supplier) ? 'supplier' : 'depo_partner' }); }); appendContactSmartAction(panel, add); }
        }
        if (can('sales.read')) await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/sales', title: 'Sales', noun: 'quotation / order', empty: 'No Sales documents linked yet.', label: (order) => `${order.name} · ${order.state} · ${formatCurrencyAmount(order.total_amount, order.currency_code)}`, open: (order) => openSalesOrder(Number(order.id)) });
        if (can('appointments.read') && selectedCompany) {
            const params = new URLSearchParams({ company_id: selectedCompany, contact_id: String(contact.id), status: 'all', limit: '100', offset: '0' });
            const result = await api(`/api/v1/appointments/bookings?${params}`); const records = result.bookings || []; const total = Number(result.total ?? records.length);
            const group = contactSmartGroup(panel, 'Appointments', `${total} booking${total === 1 ? '' : 's'}`);
            const links = document.createElement('div'); links.className = 'contact-smart-links';
            const append = (rows) => { for (const booking of rows) { const button = document.createElement('button'); button.type = 'button'; button.className = 'text-link'; button.textContent = `${booking.appointment_type} · ${booking.starts_at_utc} · ${booking.status}`; button.addEventListener('click', async () => { $('#contact-dialog').close(); await loadAppointmentOptions(selectedCompany); $('#appointments-company').value = selectedCompany; state.appointments.view = 'bookings'; state.appointments.contactFilter = { id: Number(contact.id), name: contact.name }; state.appointments.bookingFilter = { id: Number(booking.id) }; $('#appointments-status').value = 'all'; selectPage('appointments'); await loadAppointmentBookings(); updateAppointmentView(); }); links.append(button); } };
            append(records);
            if (!total) { const empty = document.createElement('span'); empty.className = 'help-text'; empty.textContent = 'No appointments linked yet.'; links.append(empty); }
            group.append(links);
            let offset = records.length;
            if (offset < total) {
                const more = document.createElement('button'); more.type = 'button'; more.className = 'button button-small'; more.textContent = `Load more (${total - offset} remaining)`;
                more.addEventListener('click', async () => { more.disabled = true; more.textContent = 'Loading…'; try { params.set('offset', String(offset)); const page = await api(`/api/v1/appointments/bookings?${params}`); const next = page.bookings || []; append(next); offset += next.length; if (!next.length || offset >= total) more.remove(); else { more.disabled = false; more.textContent = `Load more (${total - offset} remaining)`; } } catch (error) { more.disabled = false; more.textContent = 'Retry loading more'; more.title = error.message; } });
                group.append(more);
            }
            if (can('appointments.write')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New Appointment'; add.addEventListener('click', () => { $('#contact-dialog').close(); openAppointmentBooking(contact); }); group.append(add); }
        }
        if (can('invoicing.read')) {
            await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/invoicing', title: 'Customer Invoices / Credit Notes', noun: 'billing document', empty: 'No customer invoices or credit notes linked yet.', label: (invoice) => `${invoice.name} · ${invoice.document_type==='credit_note'?'Credit Note':'Invoice'} · ${invoice.state} · ${formatCurrencyAmount(invoice.total_amount, invoice.currency_code)}`, open: (invoice) => { $('#contact-dialog').close(); selectPage('invoicing'); openInvoice(Number(invoice.id)); } });
            if (Number(contact.customer) && can('invoicing.write') && can('contacts.read')) {
                const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New Direct Invoice';
                add.addEventListener('click', () => { $('#contact-dialog').close(); selectPage('invoicing'); openDirectInvoice({ companyId: selectedCompany, contactId: Number(contact.id) }); });
                appendContactSmartAction(panel, add);
            }
        }
        if (can('accounting.read')) await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/accounting/manual-entries', title: 'Manual Journal Entries', noun: 'entry', empty: 'No manual journal entries linked yet.', label: (entry) => `${entry.name} · ${entry.accounting_date} · ${entry.state} · ${formatCurrencyAmount(entry.total_debit, entry.currency_code)}`, open: (entry) => { $('#contact-dialog').close(); $('#accounting-company').value = String(entry.company_id); selectPage('accounting'); setAccountingView('entries'); openManualJournalEntry(Number(entry.id)); } });
        if (Number(contact.supplier) && can('purchase.read')) await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/purchase', title: 'Purchase', noun: 'RFQ / Purchase Order', empty: 'No Purchase documents linked yet.', label: (order) => `${order.name} · ${order.state} · ${formatCurrencyAmount(order.total_amount, order.currency_code)}`, open: (order) => { $('#contact-dialog').close(); $('#purchase-company').value = selectedCompany; selectPage('purchase'); openPurchaseDetail(Number(order.id)); } });
        if (Number(contact.supplier) && can('invoicing.read')) await appendContactSmartSection(panel, contact, selectedCompany, { endpoint: '/api/v1/purchase/bills', title: 'Vendor Bills · Credits / Refunds', noun: 'bill', empty: 'No vendor bills linked yet.', label: (bill) => { const currency=bill.currency_code||state.purchaseOptions.currency_code; const credit=Number(bill.supplier_credit_note_amount||0); const refundRegistered=Number(bill.supplier_refunded_amount||0); const appliedFromBill=Number(bill.supplier_credit_applied_amount||0); const appliedToBill=Number(bill.amount_credited||0); const open=Number(bill.supplier_credit_open_amount||0); return `${bill.name} · ${bill.state} · ${formatCurrencyAmount(bill.total_amount,currency)}${credit>0?` · Credits ${formatCurrencyAmount(credit,currency)} · Refund registered ${formatCurrencyAmount(refundRegistered,currency)} · Applied from bill ${formatCurrencyAmount(appliedFromBill,currency)} · Applied to bill ${formatCurrencyAmount(appliedToBill,currency)} · Open credit ${formatCurrencyAmount(open,currency)}`:''}`; }, open: (bill) => { $('#contact-dialog').close(); $('#purchase-company').value = selectedCompany; selectPage('purchase'); setPurchaseView('bills'); openVendorBill(Number(bill.id)); } });
        if (Number(contact.customer) && can('sales.write') && can('sales.read')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New Quotation'; add.addEventListener('click', () => { $('#contact-dialog').close(); selectPage('sales'); openSalesOrder(null, { company_id: selectedCompany, partner_contact_id: Number(contact.id) }); }); appendContactSmartAction(panel, add); }
        if (Number(contact.supplier) && can('purchase.write')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New RFQ'; add.addEventListener('click', () => { $('#contact-dialog').close(); $('#purchase-company').value = selectedCompany; selectPage('purchase'); openPurchaseOrder(null, { company_id: selectedCompany, partner_contact_id: Number(contact.id) }); }); appendContactSmartAction(panel, add); }
    }
    async function loadTodoOptions(companyId, sourceModel = '', query = '') {
        const params = new URLSearchParams({ company_id: String(companyId || state.defaultCompanyId || '') });
        if (sourceModel) params.set('source_model', sourceModel);
        if (query) params.set('q', query);
        const result = await api(`/api/v1/todo/options?${params}`);
        state.todoOptions = result.options || {};
        for (const company of state.todoOptions.companies || []) {
            const known = state.companies.find((row) => Number(row.id) === Number(company.id));
            if (known) known.name = company.name; else state.companies.push(company);
        }
        fillSelect($('#todo-company'), state.companies, { placeholder: 'Select company', keepValue: state.todoOptions.company_id });
        fillSelect($('#todo-form-company'), state.companies, { placeholder: 'Select company', keepValue: state.todoOptions.company_id });
        fillSelect($('#todo-stage-company'), state.companies, { placeholder: 'Select company', keepValue: state.todoOptions.company_id });
        fillSelect($('#todo-form-stage'), state.todoOptions.stages || [], { placeholder: 'Select stage', label: (row) => row.name });
        const assigneeSelect = $('#todo-assignees'); const selectedAssignees = new Set([...assigneeSelect.selectedOptions].map((option) => option.value));
        assigneeSelect.replaceChildren(...(state.todoOptions.assignees || []).map((row) => new Option(row.name, String(row.id))));
        [...assigneeSelect.options].forEach((option) => { option.selected = selectedAssignees.has(option.value); });
        fillSelect($('#todo-contact'), state.todoOptions.contacts || [], { placeholder: 'No linked Contact', label: (row) => row.name });
        fillSelect($('#todo-source-model'), state.todoOptions.source_models || [], { placeholder: 'No source record', valueKey: 'value', label: (row) => row.name });
        fillSelect($('#todo-source-record'), state.todoOptions.sources || [], { placeholder: sourceModel ? 'Select a source record' : 'Choose an application first', label: (row) => row.label });
        $('#todo-contact-wrap').hidden = !can('contacts.read');
        $('#todo-source-model').disabled = !can('todo.write') || !state.todoOptions.source_models?.length;
        $('#todo-source-search').disabled = !can('todo.write') || !$('#todo-source-model').value;
        $('#todo-source-search-button').disabled = !can('todo.write') || !$('#todo-source-model').value;
        $('#todo-source-record').disabled = !can('todo.write') || !$('#todo-source-model').value;
        return state.todoOptions;
    }

    async function loadTodoWorkspace() {
        if (!can('todo.read')) return;
        const companyId = String($('#todo-company').value || state.todoCompanyId || state.defaultCompanyId || state.companies[0]?.id || '');
        if (!companyId) { $('#todo-board').replaceChildren(Object.assign(document.createElement('p'), { className: 'empty-cell', textContent: 'Select an allowed company to load tasks.' })); return; }
        state.todoCompanyId = Number(companyId);
        $('#todo-company').value = companyId;
        try {
            await loadTodoOptions(companyId);
            state.todoFoldedStages = new Set((state.todoOptions.stages || []).filter((stage) => Number(stage.folded_by_default)).map((stage) => Number(stage.id)));
            $('#todo-company').value = companyId;
            await loadTodoTasks();
            showMessage($('#todo-message'));
        } catch (error) { showMessage($('#todo-message'), error.message, true); }
    }

    async function loadTodoTasks(append = false) {
        if (!can('todo.read') || !state.todoCompanyId) return;
        const offset = append ? state.todoRecords.length : 0;
        const params = new URLSearchParams({ company_id: String(state.todoCompanyId), state: $('#todo-state').value, q: $('#todo-search').value.trim(), limit: '100', offset: String(offset) });
        const result = await api(`/api/v1/todo/tasks?${params}`);
        state.todoRecords = append ? [...state.todoRecords, ...(result.records || [])] : (result.records || []); state.todoTotal = Number(result.total || 0); state.todoStageCounts = result.stage_counts || {}; state.todoOffset = state.todoRecords.length;
        $('#todo-total').textContent = `${state.todoTotal} task${state.todoTotal === 1 ? '' : 's'}`;
        $('#todo-load-more').hidden = state.todoRecords.length >= state.todoTotal;
        $('#todo-load-more').disabled = false;
        renderTodoBoard();
    }

    function renderTodoBoard() {
        const board = $('#todo-board'); board.replaceChildren();
        const stages = state.todoOptions.stages || [];
        if (!stages.length) { board.append(Object.assign(document.createElement('p'), { className: 'empty-cell', textContent: 'No active task stages are configured.' })); return; }
        for (const stage of stages) {
            const column = document.createElement('section'); column.className = 'todo-column'; column.dataset.stageId = String(stage.id);
            const heading = document.createElement('div'); heading.className = 'todo-column-head';
            const title = document.createElement('h2'); title.textContent = stage.name; heading.append(title);
            const rows = state.todoRecords.filter((task) => Number(task.stage_id) === Number(stage.id));
            const count = document.createElement('span'); count.className = 'todo-count'; count.textContent = String(state.todoStageCounts[String(stage.id)] ?? rows.length); heading.append(count);
            const folded = state.todoFoldedStages.has(Number(stage.id));
            const fold = document.createElement('button'); fold.type = 'button'; fold.className = 'button button-small'; fold.textContent = folded ? 'Expand' : 'Fold'; fold.setAttribute('aria-expanded', String(!folded)); fold.addEventListener('click', () => { if (state.todoFoldedStages.has(Number(stage.id))) state.todoFoldedStages.delete(Number(stage.id)); else state.todoFoldedStages.add(Number(stage.id)); renderTodoBoard(); }); heading.append(fold);
            if (can('settings.write') && !Number(stage.is_required)) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.title = `Edit ${stage.name}`; edit.addEventListener('click', () => openTodoStage(stage)); heading.append(edit);
                const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-small'; remove.textContent = '×'; remove.title = `Delete ${stage.name}`; remove.addEventListener('click', () => deleteTodoStage(stage)); heading.append(remove);
            }
            column.append(heading);
            const taskList = document.createElement('div'); taskList.className = 'todo-column-tasks';
            taskList.hidden = folded;
            for (const task of rows) taskList.append(createTodoCard(task));
            if (!rows.length && !folded) taskList.append(Object.assign(document.createElement('span'), { className: 'help-text', textContent: 'Drop a task here' }));
            column.append(taskList);
            column.addEventListener('dragover', (event) => { event.preventDefault(); column.classList.add('drag-over'); });
            column.addEventListener('dragleave', () => column.classList.remove('drag-over'));
            column.addEventListener('drop', async (event) => {
                event.preventDefault(); column.classList.remove('drag-over');
                const taskId = Number(event.dataTransfer?.getData('text/plain') || 0);
                const task = state.todoRecords.find((item) => Number(item.id) === taskId);
                if (!taskId || !task || Number(task.stage_id) === Number(stage.id) || !can('todo.write')) return;
                try { await api(`/api/v1/todo/tasks/${taskId}`, { method: 'PATCH', body: JSON.stringify({ stage_id: Number(stage.id) }) }); await loadTodoTasks(); }
                catch (error) { showMessage($('#todo-message'), error.message, true); }
            });
            board.append(column);
        }
        if (!state.todoRecords.length) board.append(Object.assign(document.createElement('p'), { className: 'empty-cell', textContent: 'No tasks match these filters.' }));
    }

    function createTodoCard(task) {
        const card = document.createElement('article'); card.className = 'todo-card'; card.draggable = can('todo.write'); card.dataset.taskId = String(task.id);
        card.addEventListener('dragstart', (event) => { event.dataTransfer?.setData('text/plain', String(task.id)); if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'; });
        const top = document.createElement('div'); top.className = 'todo-card-top';
        const checkboxLabel = document.createElement('label'); checkboxLabel.className = 'todo-check';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = task.stage_type === 'done'; checkbox.disabled = !can('todo.write'); checkbox.setAttribute('aria-label', `Complete ${task.title}`);
        checkbox.addEventListener('change', async () => { try { await api(`/api/v1/todo/tasks/${task.id}/complete`, { method: 'POST', body: JSON.stringify({}) }); await loadTodoTasks(); } catch (error) { checkbox.checked = !checkbox.checked; showMessage($('#todo-message'), error.message, true); } });
        checkboxLabel.append(checkbox, document.createTextNode('Done')); top.append(checkboxLabel);
        const priority = document.createElement('button'); priority.type = 'button'; priority.className = `todo-priority p${Number(task.priority)}`; priority.textContent = ['Normal','Low','High','Urgent'][Number(task.priority)] || 'Normal'; priority.disabled = !can('todo.write'); priority.title = 'Change priority';
        priority.addEventListener('click', async () => { const next = (Number(task.priority) + 1) % 4; try { await api(`/api/v1/todo/tasks/${task.id}/priority`, { method: 'POST', body: JSON.stringify({ priority: next }) }); await loadTodoTasks(); } catch (error) { showMessage($('#todo-message'), error.message, true); } }); top.append(priority); card.append(top);
        const title = document.createElement('div'); title.className = 'todo-card-title'; title.textContent = task.title; card.append(title);
        const meta = document.createElement('div'); meta.className = 'todo-card-meta';
        for (const value of [task.deadline ? `Due ${task.deadline}` : '', task.is_rotting ? 'Rotting' : '', task.tag, task.contact_name ? `Contact: ${task.contact_name}` : '', task.source_reference ? `Linked: ${task.source_reference}` : (task.has_source ? 'Linked record (access restricted)' : ''), task.reminder ? 'Reminder' : '', ...(task.assignees || []).map((person) => person.name)]) {
            if (value) { const badge = document.createElement('span'); badge.className = value === 'Rotting' ? 'role-pill todo-stale' : 'role-pill'; badge.textContent = value; meta.append(badge); }
        }
        if (task.description) { const description = document.createElement('p'); description.className = 'help-text'; description.textContent = task.description.length > 150 ? `${task.description.slice(0, 150)}…` : task.description; card.append(description); }
        if (meta.childElementCount) card.append(meta);
        const foot = document.createElement('div'); foot.className = 'todo-card-foot';
        const creator = document.createElement('span'); creator.className = 'help-text'; creator.textContent = task.creator_name || ''; foot.append(creator);
        const actions = document.createElement('div'); actions.className = 'todo-card-actions';
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Open'; edit.addEventListener('click', () => openTodoTask(Number(task.id))); actions.append(edit);
        if (task.source_model && task.source_record_id) { const source = document.createElement('button'); source.type = 'button'; source.className = 'button button-small'; source.textContent = 'Source'; source.addEventListener('click', () => openTodoSource(task)); actions.append(source); }
        if (can('todo.write') && !task.has_source) { const archive = document.createElement('button'); archive.type = 'button'; archive.className = 'button button-small'; archive.textContent = 'Archive'; archive.title = 'Archive this task'; archive.addEventListener('click', async () => { if (!window.confirm(`Archive “${task.title}”?`)) return; try { await api(`/api/v1/todo/tasks/${task.id}`, { method: 'DELETE' }); await loadTodoTasks(); } catch (error) { showMessage($('#todo-message'), error.message, true); } }); actions.append(archive); }
        foot.append(actions); card.append(foot); return card;
    }

    async function openTodoTask(id = null, prefill = {}) {
        if (id && !can('todo.read')) return;
        if (!id && !can('todo.write')) return;
        const form = $('#todo-form'); form.reset(); form.dataset.id = id ? String(id) : ''; state.currentTodo = null;
        setFormError('todo-form-error'); $('#todo-save-state').textContent = ''; $('#todo-dialog-title').textContent = id ? 'Loading Task…' : 'New Task';
        try {
            const task = id ? (await api(`/api/v1/todo/tasks/${id}`)).task : null; state.currentTodo = task;
            const companyId = String(task?.company_id || prefill.companyId || state.todoCompanyId || state.defaultCompanyId || state.companies[0]?.id || '');
            await loadTodoOptions(companyId, task?.source_model || '', task?.source_reference || '');
            form.elements.company_id.value = companyId; form.elements.company_id.disabled = Boolean(task);
            form.elements.title.value = task?.title || ''; form.elements.description.value = task?.description || '';
            form.elements.stage_id.value = String(task?.stage_id || state.todoOptions.stages?.find((row) => row.stage_key === 'inbox')?.id || '');
            form.elements.priority.value = String(task?.priority ?? 0); form.elements.tag.value = task?.tag || ''; form.elements.deadline.value = task?.deadline || ''; form.elements.reminder.checked = Boolean(task?.reminder);
            const selectedAssignees = new Set((task?.assignee_ids || (state.user?.id ? [state.user.id] : [])).map(Number));
            [...$('#todo-assignees').options].forEach((option) => { option.selected = selectedAssignees.has(Number(option.value)); });
            $('#todo-contact').value = String(task?.contact_id || prefill.contactId || '');
            $('#todo-source-model').value = task?.source_model || '';
            if (task?.source_model && task?.source_record_id) {
                const options = await loadTodoOptions(companyId, task.source_model, task.source_reference || '');
                const current = (options.sources || []).find((row) => Number(row.id) === Number(task.source_record_id));
                if (current && ![...$('#todo-source-record').options].some((option) => option.value === String(current.id))) $('#todo-source-record').add(new Option(current.label, String(current.id)));
                $('#todo-source-record').value = String(task.source_record_id);
                $('#todo-source-search').value = task.source_reference || '';
            } else { $('#todo-source-record').value = ''; $('#todo-source-search').value = ''; }
            const hiddenSource = Boolean(task?.has_source && !task.source_model);
            $('#todo-source-model').disabled = hiddenSource || !can('todo.write') || !state.todoOptions.source_models?.length;
            $('#todo-source-search').disabled = hiddenSource || !can('todo.write') || !$('#todo-source-model').value;
            $('#todo-source-search-button').disabled = hiddenSource || !can('todo.write') || !$('#todo-source-model').value;
            $('#todo-source-record').disabled = hiddenSource || !can('todo.write') || !$('#todo-source-model').value;
            $('#todo-source-help').textContent = hiddenSource ? 'This task has a source record in an application outside your access rights. Its link is preserved and cannot be changed here.' : 'A source record can be opened from the task and remains scoped to this company.';
            if (task && !can('todo.write')) [...form.querySelectorAll('input,select,textarea')].forEach((control) => { control.disabled = true; });
            else { form.elements.company_id.disabled = Boolean(task); $('#todo-contact').disabled = !can('contacts.read') || hiddenSource; }
            form.querySelector('button[type="submit"]').hidden = !can('todo.write');
            $('#todo-dialog-title').textContent = task ? task.title : 'New Task';
            $('#todo-dialog').showModal();
        } catch (error) { setFormError('todo-form-error', error.message); $('#todo-dialog').showModal(); }
    }

    function enforceTodoAssigneeLimit(event) {
        const select = event.currentTarget; const chosen = [...select.selectedOptions];
        if (chosen.length > 5) { chosen.slice(5).forEach((option) => { option.selected = false; }); setFormError('todo-form-error', 'Assign a task to no more than five people.'); }
        else setFormError('todo-form-error');
    }

    async function searchTodoSources() {
        const companyId = $('#todo-form-company').value; const model = $('#todo-source-model').value;
        if (!companyId || !model) { fillSelect($('#todo-source-record'), [], { placeholder: 'Choose an application first' }); return; }
        try { $('#todo-source-record').value = ''; await loadTodoOptions(companyId, model, $('#todo-source-search').value.trim()); $('#todo-source-model').value = model; $('#todo-source-record').value = ''; }
        catch (error) { setFormError('todo-form-error', error.message); }
    }

    async function saveTodoTask(event) {
        event.preventDefault(); const form = $('#todo-form'); if (!can('todo.write') || !form.reportValidity()) return;
        const selectedAssignees = [...$('#todo-assignees').selectedOptions].map((option) => Number(option.value));
        if (selectedAssignees.length > 5) { setFormError('todo-form-error', 'Assign a task to no more than five people.'); return; }
        const payload = { company_id: Number(form.elements.company_id.value), title: form.elements.title.value.trim(), description: form.elements.description.value.trim(), stage_id: Number(form.elements.stage_id.value), priority: Number(form.elements.priority.value), tag: form.elements.tag.value.trim(), deadline: form.elements.deadline.value || null, reminder: form.elements.reminder.checked, assignee_ids: selectedAssignees };
        const hiddenExistingSource = Boolean(state.currentTodo?.has_source && !state.currentTodo.source_model);
        if (can('contacts.read') && !hiddenExistingSource) payload.contact_id = $('#todo-contact').value ? Number($('#todo-contact').value) : null;
        const sourceModel = $('#todo-source-model').value; const sourceId = $('#todo-source-record').value;
        if (can('todo.write') && !hiddenExistingSource) { payload.source_model = sourceModel || null; payload.source_record_id = sourceId ? Number(sourceId) : null; }
        if (payload.source_model && !payload.source_record_id) { setFormError('todo-form-error', 'Choose a linked record or clear the source application.'); return; }
        setFormError('todo-form-error'); $('#todo-save-state').textContent = 'Saving…';
        try { const id = form.dataset.id; await api(id ? `/api/v1/todo/tasks/${id}` : '/api/v1/todo/tasks', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); $('#todo-dialog').close(); await loadTodoTasks(); if (state.currentContact?.id && can('todo.read')) await loadContactTodoLinks(state.currentContact, state.contactChatter.companyId); showMessage($('#todo-message'), id ? 'Task updated.' : 'Task created.'); }
        catch (error) { setFormError('todo-form-error', error.message); }
        finally { $('#todo-save-state').textContent = ''; }
    }

    async function openTodoStage(stage = null) {
        if (!can('settings.write')) return;
        const form = $('#todo-stage-form'); form.reset(); form.dataset.id = stage ? String(stage.id) : ''; setFormError('todo-stage-error');
        const companyId = String(stage?.company_id || state.todoCompanyId || state.defaultCompanyId || state.companies[0]?.id || '');
        fillSelect($('#todo-stage-company'), state.companies, { placeholder: 'Select company', keepValue: companyId }); $('#todo-stage-company').value = companyId; $('#todo-stage-company').disabled = Boolean(stage);
        form.elements.name.value = stage?.name || ''; form.elements.rotting_days.value = String(stage?.rotting_days || 0); form.elements.folded_by_default.checked = Boolean(Number(stage?.folded_by_default));
        $('#todo-stage-title').textContent = stage ? `Edit ${stage.name}` : 'New Task Stage'; $('#todo-stage-dialog').showModal();
    }

    async function saveTodoStage(event) {
        event.preventDefault(); const form = $('#todo-stage-form'); if (!can('settings.write') || !form.reportValidity()) return;
        const id = form.dataset.id || ''; const payload = { name: form.elements.name.value.trim(), rotting_days: Number(form.elements.rotting_days.value), folded_by_default: form.elements.folded_by_default.checked };
        if (!id) payload.company_id = Number(form.elements.company_id.value);
        try { await api(id ? `/api/v1/todo/stages/${id}` : '/api/v1/todo/stages', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); $('#todo-stage-dialog').close(); await loadTodoWorkspace(); }
        catch (error) { setFormError('todo-stage-error', error.message); }
    }

    async function deleteTodoStage(stage) {
        if (!can('settings.write') || !window.confirm(`Delete “${stage.name}”? Tasks in this stage will move to Inbox.`)) return;
        try { await api(`/api/v1/todo/stages/${stage.id}`, { method: 'DELETE' }); await loadTodoWorkspace(); }
        catch (error) { showMessage($('#todo-message'), error.message, true); }
    }

    async function openTodoSource(task) {
        if (!task?.source_model || !task.source_record_id) return;
        const id = Number(task.source_record_id); const companyId = Number(task.company_id); $('#todo-dialog').close();
        if (task.source_model === 'contacts.contact') openContact(id);
        else if (task.source_model === 'crm.lead') { selectPage('crm'); await openCrmRecord(id, { companyId }); }
        else if (task.source_model === 'sales.order') { selectPage('sales'); await openSalesOrder(id); }
        else if (task.source_model === 'purchase.order') { selectPage('purchase'); await openPurchaseDetail(id); }
        else if (task.source_model === 'inventory.stock_move') { selectPage('inventory'); $('#inventory-company').value = String(companyId); await initializeInventory(); selectInventoryView('moves'); await openStockMove(id); }
        else if (task.source_model === 'invoicing.customer_invoice') { selectPage('invoicing'); await openInvoice(id); }
        else if (task.source_model === 'purchase.vendor_bill') { selectPage('purchase'); setPurchaseView('bills'); await openVendorBill(id); }
        else if (task.source_model === 'accounting.entry') { selectPage('accounting'); await initializeAccounting(String(companyId)); setAccountingView('entries'); await openManualJournalEntry(id); }
        else if (task.source_model === 'contracts.contract') { $('#contracts-company').value = String(companyId); selectPage('contracts'); await initializeContracts(String(companyId)); await openContract(id); }
        else if (task.source_model === 'appointments.booking') { await loadAppointmentOptions(companyId); $('#appointments-company').value = String(companyId); state.appointments.view = 'bookings'; state.appointments.contactFilter = null; state.appointments.bookingFilter = { id }; $('#appointments-status').value = 'all'; selectPage('appointments'); await loadAppointmentBookings(); updateAppointmentView(); }
    }

    async function loadContactTodoLinks(contact, companyId) {
        if (!contact?.id || !can('todo.read') || !can('contacts.read')) return;
        const panel = $('#contact-crm-smart-panel'); const selectedCompany = String(companyId || state.defaultCompanyId || '');
        const priorButton = panel.querySelector('[data-contact-smart-key="todo"]');
        if (priorButton) {
            const priorContent = document.getElementById(priorButton.getAttribute('aria-controls'));
            const wasActive = priorButton.getAttribute('aria-expanded') === 'true';
            priorButton.remove(); priorContent?.closest('.contact-smart-group')?.remove();
            if (wasActive) {
                const nextButton = panel.querySelector('.contact-smart-panel-button');
                const nextGroup = nextButton && document.getElementById(nextButton.getAttribute('aria-controls'))?.closest('.contact-smart-group');
                if (nextButton && nextGroup) {
                    nextGroup.hidden = false; nextGroup.querySelector('.contact-smart-group-content').hidden = false;
                    nextButton.setAttribute('aria-expanded', 'true');
                }
            }
        }
        const params = new URLSearchParams({ company_id: selectedCompany, contact_id: String(contact.id), state: 'all', limit: '100', offset: '0' });
        const result = await api(`/api/v1/todo/tasks?${params}`); const records = result.records || []; const total = Number(result.total ?? records.length);
        const group = contactSmartGroup(panel, 'To-do', `${total} task${total === 1 ? '' : 's'}`, 'todo');
        const links = document.createElement('div'); links.className = 'contact-smart-links';
        const append = (rows) => { for (const task of rows) { const button = document.createElement('button'); button.type = 'button'; button.className = 'text-link'; button.textContent = `${task.title} · ${task.stage_name}${task.deadline ? ` · ${task.deadline}` : ''}`; button.addEventListener('click', () => { $('#contact-dialog').close(); openTodoTask(Number(task.id)); }); links.append(button); } };
        append(records);
        if (!total) { const empty = document.createElement('span'); empty.className = 'help-text'; empty.textContent = 'No To-do tasks linked yet.'; links.append(empty); }
        group.append(links);
        let offset = records.length;
        if (offset < total) {
            const more = document.createElement('button'); more.type = 'button'; more.className = 'button button-small'; more.textContent = `Load more (${total - offset} remaining)`;
            more.addEventListener('click', async () => { more.disabled = true; more.textContent = 'Loading…'; try { params.set('offset', String(offset)); const page = await api(`/api/v1/todo/tasks?${params}`); const next = page.records || []; append(next); offset += next.length; if (!next.length || offset >= total) more.remove(); else { more.disabled = false; more.textContent = `Load more (${total - offset} remaining)`; } } catch (error) { more.disabled = false; more.textContent = 'Retry loading more'; more.title = error.message; } });
            group.append(more);
        }
        if (can('todo.write')) { const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = 'New Task'; add.addEventListener('click', () => { $('#contact-dialog').close(); openTodoTask(null, { companyId: selectedCompany, contactId: Number(contact.id) }); }); group.append(add); }
    }

    function configureContactChatter() {
        const writable = can('contacts.write');
        for (const id of ['contact-chatter-send', 'contact-chatter-note', 'contact-chatter-activity']) $(`#${id}`).hidden = !writable;
        $('#contact-message-body').disabled = !writable;
        for (const id of ['contact-activity-type', 'contact-activity-summary', 'contact-activity-due', 'contact-activity-assignee', 'contact-message-save', 'contact-activity-save']) $(`#${id}`).disabled = !writable;
    }
    async function loadContactChatter(contact, companyId, append = false) {
        if (!contact?.id || !companyId || !can('contacts.read')) return;
        const chatter = state.contactChatter;
        if (!append || chatter.contactId !== Number(contact.id) || chatter.companyId !== Number(companyId)) {
            state.contactChatter = { contactId: Number(contact.id), companyId: Number(companyId), messages: [], activities: [], messageTotal: 0, activityTotal: 0 };
        }
        const current = state.contactChatter;
        const query = new URLSearchParams({ company_id: String(companyId), limit: '30', message_offset: String(append ? current.messages.length : 0), activity_offset: String(append ? current.activities.length : 0) });
        $('#contact-chatter').hidden = false;
        if (!append) $('#contact-chatter-timeline').replaceChildren(Object.assign(document.createElement('p'), { className: 'help-text', textContent: 'Loading history…' }));
        const result = await api(`/api/v1/contacts/${Number(contact.id)}/chatter?${query}`);
        if (state.currentContact?.id !== Number(contact.id)) return;
        const updated = state.contactChatter;
        updated.messages = append ? [...updated.messages, ...(result.messages || [])] : (result.messages || []);
        updated.activities = append ? [...updated.activities, ...(result.activities || [])] : (result.activities || []);
        updated.messageTotal = Number(result.message_total || 0); updated.activityTotal = Number(result.activity_total || 0);
        const assignee = $('#contact-activity-assignee');
        assignee.replaceChildren(...(result.assignees || []).map((user) => new Option(user.name, String(user.id))));
        if (state.user?.id && (result.assignees || []).some((user) => Number(user.id) === Number(state.user.id))) assignee.value = String(state.user.id);
        configureContactChatter(); renderContactChatter();
    }
    function renderContactChatter() {
        const chatter = state.contactChatter; const timeline = $('#contact-chatter-timeline'); timeline.replaceChildren();
        const entries = [
            ...chatter.messages.map((item) => ({ ...item, kind: item.message_type, happenedAt: item.created_at })),
            ...chatter.activities.map((item) => ({ ...item, kind: 'activity', happenedAt: item.created_at })),
        ].sort((left, right) => new Date(left.happenedAt).getTime() - new Date(right.happenedAt).getTime());
        if (!entries.length) { const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'No chatter messages, notes, or activities yet.'; timeline.append(empty); }
        for (const entry of entries) {
            const card = document.createElement('article'); card.className = `chatter-entry ${entry.kind === 'note' ? 'is-note' : ''}`;
            const heading = document.createElement('div'); heading.className = 'chatter-entry-head';
            const title = document.createElement('strong');
            const when = document.createElement('time'); when.dateTime = String(entry.happenedAt || ''); when.textContent = entry.happenedAt ? new Date(entry.happenedAt).toLocaleString() : '';
            if (entry.kind === 'activity') { title.textContent = `${entry.activity_type}: ${entry.summary}`; heading.append(title, when); }
            else { title.textContent = entry.kind === 'note' ? `Note · ${entry.author_name}` : `Message · ${entry.author_name}`; heading.append(title, when); }
            card.append(heading);
            if (entry.kind === 'activity') {
                const details = document.createElement('p'); details.className = 'chatter-entry-body';
                details.textContent = `Assigned to ${entry.assigned_name || '—'}${entry.due_at ? ` · Due ${entry.due_at.slice(0, 10)}` : ''} · ${entry.state}`; card.append(details);
                if (entry.state === 'planned' && can('contacts.write')) { const done = document.createElement('button'); done.type = 'button'; done.className = 'button button-small'; done.textContent = 'Mark done'; done.addEventListener('click', () => completeContactActivity(entry.id)); card.append(done); }
            } else { const body = document.createElement('p'); body.className = 'chatter-entry-body'; body.textContent = entry.body; card.append(body); }
            timeline.append(card);
        }
        const hasMore = chatter.messages.length < chatter.messageTotal || chatter.activities.length < chatter.activityTotal;
        $('#contact-chatter-more').hidden = !hasMore;
        if (hasMore) { const more = $('#contact-chatter-more'); more.disabled = false; more.textContent = `Load older history (${chatter.messageTotal - chatter.messages.length + chatter.activityTotal - chatter.activities.length} remaining)`; }
    }
    function showContactMessageComposer(type) {
        state.contactMessageType = type;
        $('#contact-message-label').firstChild.textContent = type === 'note' ? 'Internal note' : 'Message';
        $('#contact-message-body').placeholder = type === 'note' ? 'Write an internal note…' : 'Write a message…';
        $('#contact-message-save').textContent = type === 'note' ? 'Log note' : 'Send';
        $('#contact-activity-composer').hidden = true; $('#contact-message-composer').hidden = false;
        showMessage($('#contact-message-error'), ''); $('#contact-message-body').focus();
    }
    async function saveContactMessage() {
        const chatter = state.contactChatter; const button = $('#contact-message-save'); button.disabled = true;
        try {
            const result = await api(`/api/v1/contacts/${chatter.contactId}/messages`, { method: 'POST', body: JSON.stringify({ company_id: chatter.companyId, message_type: state.contactMessageType, body: $('#contact-message-body').value }) });
            $('#contact-message-body').value = ''; $('#contact-message-composer').hidden = true;
            await loadContactChatter(state.currentContact, chatter.companyId);
        } catch (error) { showMessage($('#contact-message-error'), error.message, true); }
        finally { button.disabled = !can('contacts.write'); }
    }
    async function saveContactActivity() {
        const chatter = state.contactChatter; const button = $('#contact-activity-save'); button.disabled = true;
        try {
            await api(`/api/v1/contacts/${chatter.contactId}/activities`, { method: 'POST', body: JSON.stringify({ company_id: chatter.companyId, activity_type: $('#contact-activity-type').value, summary: $('#contact-activity-summary').value, due_at: $('#contact-activity-due').value || null, assigned_user_id: $('#contact-activity-assignee').value }) });
            $('#contact-activity-summary').value = ''; $('#contact-activity-due').value = ''; $('#contact-activity-composer').hidden = true;
            await loadContactChatter(state.currentContact, chatter.companyId);
        } catch (error) { showMessage($('#contact-activity-error'), error.message, true); }
        finally { button.disabled = !can('contacts.write'); }
    }
    async function completeContactActivity(activityId) {
        const chatter = state.contactChatter;
        try { await api(`/api/v1/contacts/${chatter.contactId}/activities/${activityId}/done`, { method: 'POST', body: JSON.stringify({ company_id: chatter.companyId }) }); await loadContactChatter(state.currentContact, chatter.companyId); }
        catch (error) { showMessage($('#contact-chatter-error'), error.message, true); }
    }
    function setContactFormEditable(editable) {
        const form = $('#contact-form');
        $$('input,select,textarea', form).filter((control) => !control.closest('#contact-chatter')).forEach((control) => { control.disabled = !editable; });
        const contactsRead = state.lookup.contacts_read_access !== false;
        const accountingAccess = state.lookup.accounting_access !== false;
        form.elements.parent_contact_id.disabled = !editable || !contactsRead;
        form.elements.receivable_account_id.disabled = !editable || !accountingAccess;
        form.elements.payable_account_id.disabled = !editable || !accountingAccess;
        form.elements.commission_plan_id.disabled = !editable || state.lookup.commission_assign_access !== true;
        $('#contact-commission-plan-new').disabled = !editable || state.lookup.commission_manage_access !== true || state.lookup.commission_assign_access !== true;
        $('#add-address').disabled = !editable;
        $('#add-bank-account').disabled = !editable || !can('contacts.bank.write');
        $('#add-ubo-owner').disabled = !editable || !can('contacts.ubo.write');
        form.querySelector('button[type="submit"]').disabled = !editable;
        $$('.remove-row', form).forEach((button) => { if (!editable) button.disabled = true; });
        if (!can('contacts.bank.write')) {
            $$('[data-key]', $('#bank-account-list')).forEach((control) => { control.disabled = true; });
            $$('.remove-row', $('#bank-account-list')).forEach((button) => { button.disabled = true; });
        }
        if (!can('contacts.ubo.write')) {
            $$('[data-key]', $('#ubo-owner-list')).forEach((control) => { control.disabled = true; });
            $$('.remove-row', $('#ubo-owner-list')).forEach((button) => { button.disabled = true; });
        }
    }
    function updateUboControls() {
        const isCompany = $('#contact-form').elements.contact_type.value === 'company';
        const allowed = can('contacts.ubo.write');
        const contactEditable = state.currentContact ? can('contacts.write') : Boolean(state.lookup.contact_create_access);
        $('#add-ubo-owner').disabled = !isCompany || !allowed || !contactEditable;
        $('#add-ubo-owner').title = isCompany ? '' : 'Beneficial owners can only be added to a company contact.';
    }

    function cardField(label, key, value = '', type = 'text', extraClass = '') {
        const wrapper = document.createElement('label'); if (extraClass) wrapper.className = extraClass;
        wrapper.append(document.createTextNode(label));
        const input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
        if (type !== 'textarea') input.type = type;
        input.dataset.key = key; input.value = value ?? ''; wrapper.append(input); return wrapper;
    }
    function cardSelect(label, key, value, choices) {
        const wrapper = document.createElement('label'); wrapper.append(document.createTextNode(label));
        const select = document.createElement('select'); select.dataset.key = key;
        for (const [optionValue, text] of choices) select.add(new Option(text, optionValue));
        select.value = value || ''; wrapper.append(select); return wrapper;
    }
    function repeatCard(container, title, id, fields) {
        const card = document.createElement('div'); card.className = 'repeat-card'; card.dataset.id = id || '';
        const head = document.createElement('div'); head.className = 'repeat-card-head';
        const caption = document.createElement('span'); caption.textContent = title;
        const remove = document.createElement('button'); remove.className = 'remove-row'; remove.type = 'button'; remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            if (id && container.id === 'bank-account-list') state.bankRemoved.push(Number(id));
            if (id && container.id === 'ubo-owner-list') state.uboRemoved.push(Number(id));
            card.remove();
        });
        head.append(caption, remove); card.append(head);
        for (const field of fields) card.append(field);
        container.append(card); return card;
    }
    function renderAddresses(addresses) {
        const container = $('#address-list'); container.replaceChildren();
        for (const address of addresses) {
            repeatCard(container, address.address_type || 'Address', address.id, [
                cardSelect('Address Type', 'address_type', address.address_type, [['contact','Contact'],['invoice','Invoice'],['delivery','Delivery'],['face_center','Face Center'],['other','Other']]),
                cardField('Label', 'name', address.name, 'text'), cardField('Street', 'street', address.street, 'text'),
                cardField('Street 2', 'street2', address.street2, 'text'), cardField('City', 'city', address.city, 'text'),
                cardField('State', 'state', address.state, 'text'), cardField('ZIP', 'postal_code', address.postal_code, 'text'),
                cardField('Country Code', 'country_code', address.country_code, 'text'), cardField('Phone', 'phone', address.phone, 'tel'),
                cardField('Email', 'email', address.email, 'email'), cardSelect('Default', 'is_default', Number(address.is_default) ? '1' : '0', [['0','No'],['1','Yes']]),
            ]);
        }
    }
    function addAddress() {
        repeatCard($('#address-list'), 'Related Address', null, [
            cardSelect('Address Type', 'address_type', 'invoice', [['contact','Contact'],['invoice','Invoice'],['delivery','Delivery'],['face_center','Face Center'],['other','Other']]),
            cardField('Label', 'name'), cardField('Street', 'street'), cardField('Street 2', 'street2'), cardField('City', 'city'),
            cardField('State', 'state'), cardField('ZIP', 'postal_code'), cardField('Country Code', 'country_code', 'BD'),
            cardField('Phone', 'phone', '', 'tel'), cardField('Email', 'email', '', 'email'),
            cardSelect('Default', 'is_default', '0', [['0','No'],['1','Yes']]),
        ]);
    }
    function renderBankAccounts(accounts) {
        const container = $('#bank-account-list'); container.replaceChildren();
        if (!can('contacts.bank.read') && !can('contacts.bank.write')) {
            const message = document.createElement('p'); message.className = 'help-text'; message.textContent = 'Bank details are restricted for this account.'; container.append(message); return;
        }
        for (const account of accounts) {
            const card = repeatCard(container, account.bank_name || 'Bank account', account.id, [
                cardField('Bank Name', 'bank_name', account.bank_name), cardField('Account Holder', 'account_holder', account.account_holder),
                cardField('Currency Code', 'currency_code', account.currency_code),
                cardField('Account Number (leave blank to keep saved)', 'account_number', '', 'password', 'wide'),
                cardField('Routing Number (leave blank to keep saved)', 'routing_number', '', 'password'),
                cardField('IBAN (leave blank to keep saved)', 'iban', '', 'password'),
            ]);
            if (!can('contacts.bank.write')) {
                $$('.repeat-card input', card).forEach((input) => { input.disabled = true; input.value = input.dataset.key === 'bank_name' ? account.bank_name : (input.dataset.key === 'account_holder' ? (account.account_holder || '') : (input.dataset.key === 'currency_code' ? (account.currency_code || '') : (account[`${input.dataset.key}_masked`] || ''))); });
                $('.remove-row', card).hidden = true;
            }
        }
    }
    function addBankAccount() {
        if (!can('contacts.bank.write')) return;
        repeatCard($('#bank-account-list'), 'Bank Account', null, [
            cardField('Bank Name', 'bank_name'), cardField('Account Holder', 'account_holder'), cardField('Currency Code', 'currency_code', 'BDT'),
            cardField('Account Number', 'account_number', '', 'password', 'wide'), cardField('Routing Number', 'routing_number', '', 'password'), cardField('IBAN', 'iban', '', 'password'),
        ]);
    }
    function renderUboOwners(owners) {
        const container = $('#ubo-owner-list'); container.replaceChildren();
        if (!can('contacts.ubo.read') && !can('contacts.ubo.write')) {
            const message = document.createElement('p'); message.className = 'help-text'; message.textContent = 'Beneficial ownership data is restricted for this account.'; container.append(message); return;
        }
        const choices = [['', 'Select owner contact'], ...(state.lookup.contacts || []).map((contact) => [String(contact.id), `${contact.name} · ${contact.contact_type}`])];
        for (const owner of owners) {
            const card = repeatCard(container, owner.owner_name || 'Beneficial Owner', owner.id, [
                cardSelect('Owner Contact', 'owner_contact_id', String(owner.owner_contact_id), choices),
                cardField('Control Method', 'control_method', owner.control_method), cardField('Ownership %', 'ownership_percent', owner.ownership_percent, 'number'),
                cardField('Voting Rights %', 'voting_rights_percent', owner.voting_rights_percent, 'number'),
                cardField('Control Start', 'control_start_date', owner.control_start_date, 'date'), cardField('Control End', 'control_end_date', owner.control_end_date, 'date'),
            ]);
            if (!can('contacts.ubo.write')) { $$('input,select', card).forEach((input) => { input.disabled = true; }); $('.remove-row', card).hidden = true; }
        }
    }
    function addUboOwner() {
        if (!can('contacts.ubo.write')) return;
        const choices = [['', 'Select owner contact'], ...(state.lookup.contacts || []).map((contact) => [String(contact.id), `${contact.name} · ${contact.contact_type}`])];
        repeatCard($('#ubo-owner-list'), 'Beneficial Owner', null, [
            cardSelect('Owner Contact', 'owner_contact_id', '', choices), cardField('Control Method', 'control_method'),
            cardField('Ownership %', 'ownership_percent', '', 'number'), cardField('Voting Rights %', 'voting_rights_percent', '', 'number'),
            cardField('Control Start', 'control_start_date', '', 'date'), cardField('Control End', 'control_end_date', '', 'date'),
        ]);
    }

    function repeatedPayload(container, includeId = true) {
        return $$('.repeat-card', container).map((card) => {
            const row = {};
            if (includeId && card.dataset.id) row.id = Number(card.dataset.id);
            $$('[data-key]', card).forEach((control) => {
                const key = control.dataset.key;
                row[key] = control.tagName === 'SELECT' && key === 'is_default' ? control.value === '1' : (control.value.trim() || null);
                if (['owner_contact_id'].includes(key) && row[key]) row[key] = Number(row[key]);
            });
            return row;
        });
    }
    const contactScalarFields = [
        'company_id','parent_contact_id','contact_type','name','job_title','email','phone','mobile','website','tax_id','reference','language_code',
        'street','street2','city','state','postal_code','country_code','customer','supplier','employee','partner_level_id','area_territory','external_code',
        'customer_salesperson_user_id','customer_pricelist_id','customer_payment_terms_id','customer_payment_method_id','delivery_method_id','customer_incoterm_code',
        'customer_incoterm_location','avalara_code','avalara_partner_code','avalara_exemption','group_rfqs','buyer_user_id','supplier_payment_terms_id',
        'supplier_payment_method_id','supplier_currency_code','supplier_1099_box_code','receipt_reminder','supplier_incoterm_code','supplier_incoterm_location',
        'receivable_account_id','payable_account_id','auto_post_bills','peppol_id','peppol_status','fiscal_position_id','commission_plan_id','activation_stage',
        'level_weight','latest_review_date','next_review_date','partnership_date','latitude','longitude','legal_form','formation_date','internal_notes',
    ];
    function contactPayload(form) {
        const payload = {};
        for (const key of contactScalarFields) {
            if (['receivable_account_id', 'payable_account_id'].includes(key) && form.elements[key].disabled) continue;
            if (key === 'commission_plan_id' && state.lookup.commission_assign_access !== true) continue;
            if (['customer','supplier','employee','receipt_reminder'].includes(key)) payload[key] = Boolean(form.elements[key].checked);
            else payload[key] = readValue(form, key);
        }
        payload.country_code = payload.country_code?.toUpperCase() || null;
        payload.tags = (form.elements.tags_text.value || '').split(',').map((tag) => tag.trim()).filter(Boolean);
        payload.addresses = repeatedPayload($('#address-list'));
        if (can('contacts.bank.write')) {
            payload.bank_accounts = repeatedPayload($('#bank-account-list')).filter((account) => account.bank_name);
            payload.remove_bank_account_ids = state.bankRemoved;
        }
        if (can('contacts.ubo.write')) {
            payload.ubo_owners = repeatedPayload($('#ubo-owner-list')).filter((owner) => owner.owner_contact_id);
            payload.remove_ubo_owner_ids = state.uboRemoved;
        }
        return payload;
    }

    async function saveContact(event) {
        event.preventDefault(); const form = $('#contact-form');
        if (!form.reportValidity()) return;
        setFormError('contact-form-error'); $('#contact-save-state').textContent = 'Saving…';
        try {
            const id = form.dataset.id; const payload = contactPayload(form);
            const result = await api(id ? `/api/v1/contacts/${id}` : '/api/v1/contacts', {
                method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload),
            });
            state.currentContact = result.contact; $('#contact-dialog').close();
            if (!can('contacts.read')) {
                clearForm(form); form.dataset.id = '';
                showMessage($('#contacts-message'), 'Contact created. Existing Contacts remain hidden without Contacts read access.');
            } else {
                await loadContacts(); showMessage($('#contacts-message'), id ? 'Contact updated.' : 'Contact created.');
            }
        } catch (error) { setFormError('contact-form-error', error.message); }
        finally { $('#contact-save-state').textContent = ''; }
    }

    async function loadUsers() {
        if (!can('settings.write')) return;
        showMessage($('#users-message'));
        const result = await api('/api/v1/users');
        state.userRecords = result.records || [];
        renderUsers();
    }
    function renderUsers() {
        const body=$('#user-rows');body.replaceChildren();
        const query=$('#user-search').value.trim().toLocaleLowerCase();
        const records=state.userRecords.filter((user)=>!query||[user.name,user.login,user.email].some((value)=>String(value||'').toLocaleLowerCase().includes(query)));
        if(!records.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=6;cell.className='empty-cell';cell.textContent=state.userRecords.length?'No users match this search.':'No users are available in your companies.';}
        for(const user of records){const row=body.insertRow();const name=row.insertCell();const open=document.createElement('button');open.type='button';open.className='contact-link';open.textContent=user.name;open.addEventListener('click',()=>openUserProfile(Number(user.id)));name.append(open);row.insertCell().textContent=user.login;row.insertCell().textContent=user.role_name||'No access profile';row.insertCell().textContent=(user.companies||[]).map((company)=>company.name).join(', ')||'—';const status=row.insertCell();const pill=document.createElement('span');pill.className=`status-pill ${user.status==='inactive'?'cancelled':user.status==='confirmed'||user.status==='active'?'done':'confirmed'}`;pill.textContent=user.status==='active'||user.status==='confirmed'?'Confirmed':user.status==='inactive'?'Inactive':'Invited';status.append(pill);const action=row.insertCell();const button=document.createElement('button');button.type='button';button.className='button button-small';button.textContent='Open';button.addEventListener('click',()=>openUserProfile(Number(user.id)));action.append(button);}
        $('#user-total').textContent=`${state.userRecords.length} user${state.userRecords.length===1?'':'s'}`;
    }
    function moduleLabel(module){return({contacts:'Contacts',contact_master_data:'Contact',partner_commissions:'Partner Commissions',data_export:'Export',crm:'CRM',sales:'Sales',purchase:'Purchase',inventory:'Inventory',products:'Products',invoicing:'Invoice',accounting:'Accounts',contracts:'Contract',settings:'Settings',discuss:'Discuss',todo:'To-do',appointments:'Appointment',dashboards:'Dashboard'})[module]||module.replaceAll('_',' ').replace(/\b\w/g,(letter)=>letter.toUpperCase());}
    function modulePermissions(module){return state.userPermissionCatalog.filter((row)=>row.module_key===module).map((row)=>row.permission_key);}
    function moduleAccessLevels(module){if(module==='data_export')return[['no','No'],['allowed','Allowed']];return ['products','contact_master_data'].includes(module)?[['no','No'],['create',module==='products'?'Create':'Creation']]:[['no','No Access'],['user','User'],['administrator','Administrator'],['custom','Custom']];}
    function permissionLevelKeys(module,level){const keys=modulePermissions(module);if(level==='administrator')return keys;if(level==='no')return[];if(module==='data_export')return level==='allowed'?keys:[];if(['products','contact_master_data'].includes(module))return level==='create'?keys:[];if(level==='user'){if(module==='settings'||module==='accounting'||module==='partner_commissions')return keys.filter((key)=>key===`${module}.read`);return keys.filter((key)=>key===`${module}.read`||key===`${module}.write`);}return[];}
    function permissionTemplateKeys(template){const keys=[];const modules=[...new Set(state.userPermissionCatalog.map((row)=>row.module_key))];for(const module of modules){if(template==='light')keys.push(...modulePermissions(module).filter((key)=>key===`${module}.read`));else if(template==='user')keys.push(...permissionLevelKeys(module,'user'));else if(template==='administrator')keys.push(...modulePermissions(module));}return new Set(keys);}
    function currentModuleLevel(module){const keys=modulePermissions(module);const selected=state.currentUserProfile?.selectedPermissions||new Set();for(const [level] of moduleAccessLevels(module)){const expected=permissionLevelKeys(module,level);if(keys.length===expected.length&&keys.every((key)=>selected.has(key)===expected.includes(key)))return level;}return ['products','contact_master_data','data_export'].includes(module)?'no':'custom';}
    function updateUserTemplateRadios(){const selected=state.currentUserProfile?.selectedPermissions||new Set();let match='';for(const template of ['light','user','administrator']){const expected=permissionTemplateKeys(template);if(expected.size===selected.size&&[...expected].every((key)=>selected.has(key)))match=template;}$$('input[name="access_template"]',$('#user-access-form')).forEach((radio)=>{radio.checked=radio.value===match;});$('#user-custom-access').textContent=match?'':'Custom access';}
    function updateUserModuleLevel(module){const control=$(`#user-permission-${CSS.escape(module)}`);if(control)control.value=currentModuleLevel(module);}
    function syncUserPermissionCheckboxes(){const selected=state.currentUserProfile?.selectedPermissions||new Set();$$('[data-user-permission-key]',$('#user-permission-groups')).forEach((checkbox)=>{checkbox.checked=selected.has(checkbox.dataset.userPermissionKey);});for(const module of new Set(state.userPermissionCatalog.map((row)=>row.module_key)))updateUserModuleLevel(module);updateUserTemplateRadios();}
    function renderUserPermissionGroups(){const root=$('#user-permission-groups');root.replaceChildren();const selected=state.currentUserProfile.selectedPermissions;const grouped=new Map();for(const row of state.userPermissionCatalog){if(!grouped.has(row.module_key))grouped.set(row.module_key,[]);grouped.get(row.module_key).push(row);}for(const [module,permissions] of grouped){const section=document.createElement('details');section.className='user-permission-module';const summary=document.createElement('summary');const title=document.createElement('strong');title.textContent=moduleLabel(module);const level=document.createElement('select');level.id=`user-permission-${module}`;level.className='user-module-level';for(const [value,label] of moduleAccessLevels(module)){const option=new Option(label,value);if(value==='custom')option.disabled=true;level.add(option);}summary.append(title,level);section.append(summary);const list=document.createElement('div');list.className='user-permission-list';for(const permission of permissions){const label=document.createElement('label');label.className='user-permission-item';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.userPermissionKey=permission.permission_key;checkbox.checked=selected.has(permission.permission_key);const text=document.createElement('span');text.textContent=permission.description||permission.permission_key;label.append(checkbox,text);list.append(label);checkbox.addEventListener('change',()=>{if(checkbox.checked)selected.add(permission.permission_key);else selected.delete(permission.permission_key);updateUserModuleLevel(module);updateUserTemplateRadios();});}section.append(list);root.append(section);level.value=currentModuleLevel(module);level.addEventListener('change',()=>{for(const key of modulePermissions(module))selected.delete(key);for(const key of permissionLevelKeys(module,level.value))selected.add(key);syncUserPermissionCheckboxes();});}updateUserTemplateRadios();}
    function renderUserCompanies(profile){const root=$('#user-company-access');root.replaceChildren();const selected=new Set((profile.companies||[]).map((company)=>Number(company.id)));for(const company of profile.available_companies||[]){const label=document.createElement('label');label.className='check-row user-company-option';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.name='user_company_id';checkbox.value=String(company.id);checkbox.checked=selected.has(Number(company.id));const text=document.createElement('span');text.textContent=company.name;label.append(checkbox,text);root.append(label);checkbox.addEventListener('change',()=>{const previous=$('#user-default-company').value;updateUserDefaultCompanyOptions();const next=$('#user-default-company').value;if(next!==previous)loadUserWarehouseOptions(next,'').catch((error)=>{const help=$('#user-warehouse-help');if(help)help.textContent=error.message;});});}updateUserDefaultCompanyOptions();}
    function updateUserDefaultCompanyOptions(){const select=$('#user-default-company');const profile=state.currentUserProfile;const available=profile?.available_companies||[];const chosen=$$('.user-company-option input:checked',$('#user-company-access')).map((checkbox)=>Number(checkbox.value));const previous=Number(select.value||profile?.default_company_id||0);select.replaceChildren(new Option('No default company',''));for(const id of chosen){const company=available.find((row)=>Number(row.id)===id);if(company)select.add(new Option(company.name,String(id)));}if(profile?.default_company_id&&!available.some((row)=>Number(row.id)===Number(profile.default_company_id))){const company=(profile.companies||[]).find((row)=>Number(row.id)===Number(profile.default_company_id));if(company)select.add(new Option(`${company.name} · current default`,String(company.id)));}select.value=[...select.options].some((option)=>option.value===String(previous))?String(previous):(chosen[0]?String(chosen[0]):'');}

    let userWarehouseOptionsRequest=0;
    async function loadUserWarehouseOptions(companyId,selectedWarehouseId=''){
        const select=$('#user-default-warehouse');
        const form=$('#user-access-form');
        const help=$('#user-warehouse-help');
        const request=++userWarehouseOptionsRequest;
        const selected=selectedWarehouseId?String(selectedWarehouseId):'';
        form.dataset.warehouseLoading='1';
        select.disabled=true;
        select.replaceChildren(new Option(companyId?'Loading warehouses…':'Choose a default company first',''));
        if(help)help.textContent='The default warehouse must belong to the selected default company.';
        if(!companyId){if(request===userWarehouseOptionsRequest){select.replaceChildren(new Option('No default warehouse',''));select.disabled=false;form.dataset.warehouseLoading='0';}return;}
        try{
            const result=await api(`/api/v1/users/warehouse-options?company_id=${encodeURIComponent(companyId)}`);
            if(request!==userWarehouseOptionsRequest)return;
            const warehouses=result.warehouses||[];
            select.replaceChildren(new Option('No default warehouse',''));
            for(const warehouse of warehouses)select.add(new Option(`${warehouse.name} · ${warehouse.code}`,String(warehouse.id)));
            if(selected&&!warehouses.some((warehouse)=>String(warehouse.id)===selected))select.add(new Option('Saved warehouse · inactive or unavailable',selected));
            select.value=selected&&[...select.options].some((option)=>option.value===selected)?selected:'';
            if(help&&!warehouses.length)help.textContent='No active warehouses are configured for this company yet.';
        }catch(error){
            if(request!==userWarehouseOptionsRequest)return;
            select.replaceChildren(new Option('Warehouse options unavailable',''));
            if(selected){select.add(new Option('Saved warehouse · availability could not be checked',selected));select.value=selected;}
            if(help)help.textContent=error.message;
        }finally{if(request===userWarehouseOptionsRequest){select.disabled=false;form.dataset.warehouseLoading='0';}}
    }
    function setUserStatus(status){const normalized=status==='active'||status==='confirmed'?'confirmed':status==='inactive'?'inactive':'invited';const root=$('#user-status-bar');root.replaceChildren();for(const [key,label] of [['invited','Invited'],['confirmed','Confirmed'],['inactive','Inactive']]){const item=document.createElement('span');item.className=`user-status-step ${normalized===key?'active':''}`;item.textContent=label;root.append(item);}}
    function populateUserProfileForm(profile){const form=$('#user-access-form');for(const field of ['name','login','email','phone','mobile','language_code','email_signature','live_chat_name'])if(form.elements[field])form.elements[field].value=profile[field]||'';form.elements.calendar_default_view.value=profile.calendar_default_view||'month';form.elements.calendar_show_weekends.checked=profile.calendar_show_weekends!==false&&profile.calendar_show_weekends!==0;$$('input[name="theme"]',form).forEach((radio)=>{radio.checked=radio.value===(profile.theme||'system');});$$('input[name="notification_preference"]',form).forEach((radio)=>{radio.checked=radio.value===(profile.notification_preference||'email');});form.elements.spoken_languages_text.value=(profile.spoken_languages||[]).join(', ');form.elements.expertise_text.value=(profile.expertise||[]).join(', ');setUserStatus(profile.status||'invited');$('#user-phone-summary').textContent=[profile.phone,profile.mobile].filter(Boolean).join(' · ')||'No phone details on the linked Contact.';const isSelf=Number(profile.id||0)===Number(state.user?.id||0);$('#user-security-summary').textContent=`Two-factor authentication: ${Number(profile.two_factor_enabled)?'Enabled':'Not enabled'}. Password and device management are available for your own active account.`;$('#user-change-password').hidden=!isSelf||!['active','confirmed'].includes(profile.status);$('#user-two-factor-button').hidden=!isSelf||!['active','confirmed'].includes(profile.status);$('#user-two-factor-button').textContent=Number(profile.two_factor_enabled)?'Disable 2FA':'Set up 2FA';$('#user-two-factor-description').textContent=Number(profile.two_factor_enabled)?'Enabled. Use authenticator codes or single-use recovery codes at sign in.':'Use an authenticator app and single-use recovery codes at sign in.';$('#user-passkeys-button').hidden=!isSelf||!['active','confirmed'].includes(profile.status);$('#user-passkeys-button').disabled=!passkeySupported();$('#user-passkey-list').replaceChildren();$('#user-sessions-refresh').disabled=!isSelf;$('#user-session-pagination').hidden=!isSelf;$('#user-session-list').replaceChildren();if(!isSelf){const note=document.createElement('p');note.className='help-text';note.textContent='Only your own signed-in devices are visible and manageable here.';$('#user-session-list').append(note);}renderUserCompanies(profile);renderUserPermissionGroups();}
    async function openNewUserProfile(){if(!can('settings.write'))return;const form=$('#user-access-form');form.reset();form.dataset.id='';state.currentUserProfile=null;setFormError('user-access-error');$('#user-access-save-state').textContent='';$('#user-access-rights-tab').hidden=false;$('#user-access-rights').hidden=false;$('#user-profile-help').hidden=false;form.querySelector('[data-close="user-access-dialog"]').textContent='Discard';form.querySelector('button[type="submit"]').disabled=true;switchTabs($('#user-access-dialog'),'user-access-rights');try{const options=await api('/api/v1/users/options');state.userPermissionCatalog=options.permissions||[];const companies=options.companies||[];const preferred=companies.find((company)=>Number(company.id)===Number(state.user?.default_company_id))||companies[0]||null;const profile={id:null,contact_id:null,name:'',login:'',email:'',phone:'',mobile:'',status:'invited',language_code:'en_US',theme:'system',notification_preference:'email',email_signature:'',live_chat_name:'',spoken_languages:[],expertise:[],calendar_default_view:'month',calendar_show_weekends:true,default_warehouse_id:null,companies:preferred?[preferred]:[],available_companies:companies,default_company_id:preferred?Number(preferred.id):null,selectedPermissions:permissionTemplateKeys('user')};state.currentUserProfile=profile;$('#user-access-title').textContent='New User';$('#user-avatar').textContent='U';populateUserProfileForm(profile);await loadUserWarehouseOptions(profile.default_company_id,'');$('#user-contact-open').hidden=true;$('#user-contact-open').onclick=null;$$('input,select,textarea',form).forEach((control)=>{control.disabled=false;});form.querySelector('button[type="submit"]').disabled=false;form.querySelector('button[type="submit"]').textContent='Create User';$('#user-access-dialog').showModal();}catch(error){setFormError('user-access-error',error.message);$('#user-access-dialog').showModal();}}
    async function openMyPreferences(){if(!state.user?.id)return;await openUserProfile(Number(state.user.id));}
    async function openUserProfile(id){const isSelf=Number(id)===Number(state.user?.id);if(!can('settings.write')&&!isSelf)return;const form=$('#user-access-form');form.reset();form.dataset.id=String(id);state.currentUserProfile=null;setFormError('user-access-error');$('#user-access-save-state').textContent='';$('#user-access-rights-tab').hidden=false;$('#user-access-rights').hidden=false;$('#user-profile-help').hidden=true;$('#user-contact-open').hidden=true;form.querySelector('[data-close="user-access-dialog"]').textContent='Discard';form.querySelector('button[type="submit"]').disabled=true;switchTabs($('#user-access-dialog'),isSelf&&!can('settings.write')?'user-preferences':'user-access-rights');try{const result=await api(`/api/v1/users/${id}`);const profile=result.user;profile.selectedPermissions=new Set(profile.permission_keys||[]);state.currentUserProfile=profile;state.userPermissionCatalog=result.permissions||[];$('#user-access-title').textContent=profile.name;$('#user-avatar').textContent=(profile.name||'U').trim().slice(0,1).toLocaleUpperCase();populateUserProfileForm(profile);await loadUserWarehouseOptions(profile.default_company_id,profile.default_warehouse_id);const canManage=can('settings.write')&&!profile.read_only;const preferenceOnly=isSelf&&!canManage;$('#user-access-rights-tab').hidden=preferenceOnly;$('#user-access-rights').hidden=preferenceOnly;$('#user-profile-help').hidden=true;$('#user-contact-open').hidden=!can('contacts.read')||!profile.contact_id;$('#user-contact-open').onclick=()=>{if(profile.contact_id){$('#user-access-dialog').close();openContact(Number(profile.contact_id));}};const editablePreferences=new Set(['language_code','email_signature','live_chat_name','theme','notification_preference','spoken_languages_text','expertise_text','calendar_default_view','calendar_show_weekends','default_warehouse_id']);$$('input,select,textarea',form).forEach((control)=>{control.disabled=canManage?Boolean(profile.read_only):!(isSelf&&editablePreferences.has(control.name));});const save=form.querySelector('button[type="submit"]');save.disabled=!(canManage||isSelf);save.textContent=preferenceOnly?'Save Preferences':'Save';form.querySelector('[data-close="user-access-dialog"]').textContent=preferenceOnly?'Close':'Discard';if(preferenceOnly)switchTabs($('#user-access-dialog'),'user-preferences');$('#user-access-dialog').showModal();if(isSelf)loadOwnSessions();}catch(error){setFormError('user-access-error',error.message);$('#user-access-dialog').showModal();}}
    function userProfilePreferencesPayload(form){return{language_code:form.elements.language_code.value.trim()||'en_US',theme:form.elements.theme.value||'system',notification_preference:form.elements.notification_preference.value||'email',email_signature:form.elements.email_signature.value,live_chat_name:form.elements.live_chat_name.value.trim()||null,spoken_languages:form.elements.spoken_languages_text.value.split(',').map((value)=>value.trim()).filter(Boolean),expertise:form.elements.expertise_text.value.split(',').map((value)=>value.trim()).filter(Boolean),calendar_default_view:form.elements.calendar_default_view.value||'month',calendar_show_weekends:form.elements.calendar_show_weekends.checked,default_warehouse_id:form.elements.default_warehouse_id.value?Number(form.elements.default_warehouse_id.value):null};}
    function userProfilePayload(form){const preferences=userProfilePreferencesPayload(form);return{name:form.elements.name.value.trim(),login:form.elements.login.value.trim(),email:form.elements.email.value.trim()||null,phone:form.elements.phone.value.trim()||null,mobile:form.elements.mobile.value.trim()||null,company_ids:$$('.user-company-option input:checked',$('#user-company-access')).map((checkbox)=>Number(checkbox.value)),default_company_id:form.elements.default_company_id.value?Number(form.elements.default_company_id.value):null,permission_keys:[...(state.currentUserProfile.selectedPermissions||new Set())],...preferences};}
    async function saveUserProfile(event){event.preventDefault();const form=$('#user-access-form');if(!form.reportValidity()||!state.currentUserProfile)return;if(form.dataset.warehouseLoading==='1'){setFormError('user-access-error','Wait for the Default Warehouse choices to finish loading before saving.');return;}setFormError('user-access-error');$('#user-access-save-state').textContent='Saving…';try{const creating=!form.dataset.id;const savedId=Number(form.dataset.id);const isSelf=!creating&&savedId===Number(state.user?.id);const preferenceOnly=isSelf&&(!can('settings.write')||Boolean(state.currentUserProfile.read_only));const result=preferenceOnly?await api('/api/v1/users/me/preferences',{method:'PATCH',body:JSON.stringify(userProfilePreferencesPayload(form))}):await api(creating?'/api/v1/users':`/api/v1/users/${savedId}`,{method:creating?'POST':'PATCH',body:JSON.stringify(userProfilePayload(form))});state.currentUserProfile={...result.user,selectedPermissions:new Set(result.user.permission_keys||[])};$('#user-access-dialog').close();if(isSelf)await loadSession();if(can('settings.write'))await loadUsers();const message=creating?'User record created as a pending invitation. Email delivery is not connected yet.':preferenceOnly?'Your preferences were saved.':'User profile and access rights saved.';showMessage($('#users-message'),message);showMessage($('#system-notice'),message);}catch(error){setFormError('user-access-error',error.message);}finally{$('#user-access-save-state').textContent='';}}

    function userDeviceLabel(userAgent){const browser=/Edg\//.test(userAgent)?'Edge':/OPR\//.test(userAgent)?'Opera':/Firefox\//.test(userAgent)?'Firefox':/Chrome\//.test(userAgent)?'Chrome':/Safari\//.test(userAgent)?'Safari':'Browser';const platform=/Windows/i.test(userAgent)?'Windows':/Android/i.test(userAgent)?'Android':/iPhone|iPad|iPod/i.test(userAgent)?'iOS':/Mac OS|Macintosh/i.test(userAgent)?'macOS':/Linux/i.test(userAgent)?'Linux':'Unknown device';return`${platform} · ${browser}`;}
    function renderOwnSessions(sessions,total,offset,limit){const root=$('#user-session-list');root.replaceChildren();const first=total?offset+1:0;const last=total?Math.min(total,offset+sessions.length):0;$('#user-session-page-label').textContent=`${first}-${last} / ${total}`;$('#user-session-previous').disabled=offset<=0;$('#user-session-next').disabled=offset+limit>=total;if(!sessions.length){const empty=document.createElement('p');empty.className='help-text';empty.textContent='No active device sessions were found.';root.append(empty);return;}for(const session of sessions){const row=document.createElement('div');row.className='user-session-row';const details=document.createElement('div');details.className='user-session-device';const device=document.createElement('strong');device.textContent=userDeviceLabel(session.user_agent||'');const address=document.createElement('span');address.textContent=session.ip_address||'IP address unavailable';const seen=document.createElement('small');const date=new Date(`${session.last_seen_at}Z`);seen.textContent=`${session.is_current?'This device · ':''}${Number.isNaN(date.getTime())?'Last seen time unavailable':`Last active ${date.toLocaleString()}`}`;details.append(device,address,seen);row.append(details);if(!session.is_current){const logout=document.createElement('button');logout.type='button';logout.className='button button-small';logout.textContent='Log out';logout.addEventListener('click',async()=>{logout.disabled=true;try{await api(`/api/v1/users/me/sessions/${Number(session.id)}/logout`,{method:'POST',body:JSON.stringify({})});await loadOwnSessions();showMessage($('#users-message'),'Device session ended.');}catch(error){logout.disabled=false;showMessage($('#users-message'),error.message,true);}});row.append(logout);}root.append(row);}}
    async function loadOwnSessions(offset=state.userSessionOffset){const profile=state.currentUserProfile;if(!profile||Number(profile.id)!==Number(state.user?.id))return;const refresh=$('#user-sessions-refresh');refresh.disabled=true;try{const safeOffset=Math.max(0,Number(offset)||0);const query=new URLSearchParams({offset:String(safeOffset)});const result=await api(`/api/v1/users/me/sessions?${query}`);const total=Number(result.total||0);if(safeOffset>0&&safeOffset>=total){const previous=total?Math.floor((total-1)/state.userSessionLimit)*state.userSessionLimit:0;return await loadOwnSessions(previous);}state.userSessionOffset=safeOffset;state.userSessionTotal=total;state.userSessionLimit=Number(result.limit||40);renderOwnSessions(result.sessions||[],total,safeOffset,state.userSessionLimit);}catch(error){const root=$('#user-session-list');root.replaceChildren();const note=document.createElement('p');note.className='inline-message error';note.textContent=error.message;root.append(note);}finally{refresh.disabled=false;}}
    function openPasswordChange(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id)||!state.user?.id)return;const form=$('#password-change-form');form.reset();setFormError('password-change-error');$('#password-change-state').textContent='';$('#password-change-dialog').showModal();}
    async function saveOwnPassword(event){event.preventDefault();const form=$('#password-change-form');if(!form.reportValidity())return;setFormError('password-change-error');$('#password-change-state').textContent='Saving…';try{const payload=Object.fromEntries(new FormData(form).entries());const result=await api('/api/v1/users/me/password',{method:'POST',body:JSON.stringify(payload)});$('#password-change-dialog').close();form.reset();await loadOwnSessions();showMessage($('#users-message'),`Password updated. ${Number(result.other_sessions_revoked||0)} other device session(s) signed out.`);}catch(error){setFormError('password-change-error',error.message);}finally{$('#password-change-state').textContent='';}}

    async function loadTwoFactorStatus(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;try{const result=await api('/api/v1/users/me/two-factor');const profile=state.currentUserProfile;profile.two_factor_enabled=Boolean(result.enabled);$('#user-security-summary').textContent=`Two-factor authentication: ${result.enabled?'Enabled':'Not enabled'}${result.enabled?` · ${Number(result.recovery_codes_remaining)} recovery code(s) remain`:''}. Password and device management are available for your own active account.`;$('#user-two-factor-button').textContent=result.enabled?'Disable 2FA':'Set up 2FA';$('#user-two-factor-description').textContent=result.enabled?'Enabled. Use authenticator codes or single-use recovery codes at sign in.':'Use an authenticator app and single-use recovery codes at sign in.';}catch(error){$('#user-security-summary').textContent=error.message;}}
    function openTwoFactorSetup(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;const form=$('#two-factor-form');form.reset();form.elements.enrollment_code.required=false;setFormError('two-factor-error');$('#two-factor-state').textContent='';$('#two-factor-enroll-step').hidden=false;$('#two-factor-confirm-step').hidden=true;$('#two-factor-recovery-step').hidden=true;$('#two-factor-dialog').showModal();}
    async function beginTwoFactorSetup(){const form=$('#two-factor-form');const password=form.elements.current_password.value;if(!password){form.elements.current_password.reportValidity();return;}$('#two-factor-begin').disabled=true;$('#two-factor-state').textContent='Checking password…';setFormError('two-factor-error');try{const setup=await api('/api/v1/users/me/two-factor/enroll',{method:'POST',body:JSON.stringify({current_password:password})});$('#two-factor-secret').value=setup.secret;const link=$('#two-factor-provisioning-link');link.href=setup.provisioning_uri;link.textContent='Open authenticator setup link';$('#two-factor-enroll-step').hidden=true;$('#two-factor-confirm-step').hidden=false;form.elements.enrollment_code.required=true;form.elements.enrollment_code.focus();}catch(error){setFormError('two-factor-error',error.message);}finally{$('#two-factor-begin').disabled=false;$('#two-factor-state').textContent='';}}
    async function confirmTwoFactorSetup(){const form=$('#two-factor-form');const code=form.elements.enrollment_code.value.trim();if(!/^\d{6}$/.test(code)){setFormError('two-factor-error','Enter the current six-digit authenticator code.');return;}$('#two-factor-confirm').disabled=true;$('#two-factor-state').textContent='Verifying…';setFormError('two-factor-error');try{const result=await api('/api/v1/users/me/two-factor/confirm',{method:'POST',body:JSON.stringify({code})});const root=$('#two-factor-recovery-codes');root.replaceChildren();for(const value of result.recovery_codes||[]){const item=document.createElement('code');item.textContent=value;root.append(item);}$('#two-factor-confirm-step').hidden=true;$('#two-factor-recovery-step').hidden=false;await loadTwoFactorStatus();}catch(error){setFormError('two-factor-error',error.message);}finally{$('#two-factor-confirm').disabled=false;$('#two-factor-state').textContent='';}}
    async function disableTwoFactor(event){event.preventDefault();const form=$('#two-factor-disable-form');if(!form.reportValidity())return;setFormError('two-factor-disable-error');$('#two-factor-disable-state').textContent='Verifying…';try{await api('/api/v1/users/me/two-factor/disable',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))});$('#two-factor-disable-dialog').close();form.reset();await loadTwoFactorStatus();showMessage($('#users-message'),'Two-factor authentication disabled.');}catch(error){setFormError('two-factor-disable-error',error.message);}finally{$('#two-factor-disable-state').textContent='';}}
    function manageTwoFactor(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;if(state.currentUserProfile.two_factor_enabled){const form=$('#two-factor-disable-form');form.reset();setFormError('two-factor-disable-error');$('#two-factor-disable-state').textContent='';$('#two-factor-disable-dialog').showModal();}else openTwoFactorSetup();}
    async function loadApiKeys(){const root=$('#api-key-list');root.replaceChildren();try{const result=await api('/api/v1/users/me/api-keys');if(!(result.api_keys||[]).length){const empty=document.createElement('p');empty.className='help-text';empty.textContent='No API keys have been created.';root.append(empty);return;}for(const item of result.api_keys){const row=document.createElement('div');row.className='api-key-row';const details=document.createElement('div');details.className='api-key-details';const name=document.createElement('strong');name.textContent=item.name;const prefix=document.createElement('code');prefix.textContent=`${item.key_prefix}…`;const meta=document.createElement('small');const expired=item.expires_at&&new Date(`${item.expires_at}Z`).getTime()<=Date.now();meta.textContent=item.revoked_at?'Revoked':expired?'Expired':`Expires ${item.expires_at||'never'} · Last used ${item.last_used_at||'never'}`;details.append(name,prefix,meta);row.append(details);if(!item.revoked_at&&!expired){const revoke=document.createElement('button');revoke.type='button';revoke.className='button button-small';revoke.textContent='Revoke';revoke.addEventListener('click',async()=>{if(!window.confirm(`Revoke API key “${item.name}”? Applications using it will lose access.`))return;revoke.disabled=true;try{await api(`/api/v1/users/me/api-keys/${Number(item.id)}`,{method:'DELETE'});await loadApiKeys();}catch(error){revoke.disabled=false;setFormError('api-key-error',error.message);}});row.append(revoke);}root.append(row);}}catch(error){setFormError('api-key-error',error.message);}}
    async function openApiKeys(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;const form=$('#api-key-create-form');form.reset();$('#api-key-secret-panel').hidden=true;$('#api-key-create-section').hidden=false;$('#api-key-secret').value='';setFormError('api-key-error');$('#api-key-state').textContent='';$('#api-key-dialog').showModal();await loadApiKeys();}
    async function createApiKey(event){event.preventDefault();const form=$('#api-key-create-form');if(!form.reportValidity())return;setFormError('api-key-error');$('#api-key-state').textContent='Creating…';const submit=form.querySelector('[type="submit"]');submit.disabled=true;try{const payload=Object.fromEntries(new FormData(form).entries());const result=await api('/api/v1/users/me/api-keys',{method:'POST',body:JSON.stringify(payload)});$('#api-key-secret').value=result.api_key.token;$('#api-key-secret-panel').hidden=false;form.elements.current_password.value='';await loadApiKeys();}catch(error){setFormError('api-key-error',error.message);}finally{submit.disabled=false;$('#api-key-state').textContent='';}}
    async function copyApiKey(){const input=$('#api-key-secret');try{await navigator.clipboard.writeText(input.value);$('#api-key-state').textContent='Copied.';}catch{input.focus();input.select();$('#api-key-state').textContent='Select and copy the key manually.';}}

    function passkeySupported(){return Boolean(window.isSecureContext&&navigator.credentials&&window.PublicKeyCredential);}
    function decodeBase64url(value){const normalized=String(value).replace(/-/g,'+').replace(/_/g,'/');const binary=atob(normalized+'='.repeat((4-normalized.length%4)%4));return Uint8Array.from(binary,(character)=>character.charCodeAt(0));}
    function encodeBase64url(buffer){const bytes=new Uint8Array(buffer);let binary='';for(const byte of bytes)binary+=String.fromCharCode(byte);return btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
    function passkeyPayload(credential,creation){const response=credential.response;const payload={id:credential.id,raw_id:encodeBase64url(credential.rawId),type:credential.type,response:{client_data_json:encodeBase64url(response.clientDataJSON)}};if(creation){payload.response.attestation_object=encodeBase64url(response.attestationObject);payload.response.transports=typeof response.getTransports==='function'?response.getTransports():[];}else{payload.response.authenticator_data=encodeBase64url(response.authenticatorData);payload.response.signature=encodeBase64url(response.signature);payload.response.user_handle=response.userHandle?encodeBase64url(response.userHandle):'';}return payload;}
    async function loadOwnPasskeys(){const root=$('#user-passkey-list');root.replaceChildren();if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;try{const result=await api('/api/v1/users/me/passkeys');if(!(result.passkeys||[]).length){const empty=document.createElement('p');empty.className='help-text';empty.textContent='No passkeys are registered for this account.';root.append(empty);return;}for(const item of result.passkeys){const row=document.createElement('div');row.className='user-passkey-row';const details=document.createElement('div');details.className='user-passkey-details';const name=document.createElement('strong');name.textContent=item.display_name;const meta=document.createElement('small');meta.textContent=`Added ${item.created_at||'date unavailable'} · Last used ${item.last_used_at||'never'}`;details.append(name,meta);row.append(details);const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>openPasskeyRemoval(item));row.append(remove);root.append(row);}}catch(error){const note=document.createElement('p');note.className='inline-message error';note.textContent=error.message;root.append(note);}}
    async function loginWithPasskey(){if(!passkeySupported()){setFormError('login-error','Passkeys require a supported browser and HTTPS (localhost is allowed).');return;}const button=$('#login-passkey');button.disabled=true;setFormError('login-error');try{const {options}=await api('/api/v1/auth/passkey/options',{method:'POST',body:JSON.stringify({})});const credential=await navigator.credentials.get({publicKey:{challenge:decodeBase64url(options.challenge),rpId:options.rpId,timeout:options.timeout,userVerification:options.userVerification}});if(!credential)throw new Error('No passkey was selected.');const result=await api('/api/v1/auth/passkey/verify',{method:'POST',body:JSON.stringify(passkeyPayload(credential,false))});state.csrf=result.csrf_token||state.csrf;if(result.requires_two_factor){state.loginTwoFactorPending=true;$('#login-user-field').hidden=true;$('#login-password-field').hidden=true;$('#login-two-factor-field').hidden=false;$('#login-passkey').hidden=true;$('#login-submit').textContent='Verify code';$('#login-two-factor-cancel').hidden=false;const form=$('#login-form');form.elements.two_factor_code.required=true;form.elements.two_factor_code.focus();return;}await loadSession();}catch(error){setFormError('login-error',error.message);}finally{button.disabled=false;}}
    async function openPasskeyManager(){if(Number(state.currentUserProfile?.id)!==Number(state.user?.id))return;if(!passkeySupported()){showMessage($('#users-message'),'Passkeys require a supported browser and HTTPS (localhost is allowed).',true);return;}const form=$('#passkey-register-form');form.reset();form.elements.display_name.value='My Passkey';setFormError('passkey-error');$('#passkey-state').textContent='';$('#passkey-dialog').showModal();}
    function openPasskeyRemoval(passkey){const form=$('#passkey-remove-form');form.reset();form.dataset.passkeyId=String(passkey.id);$('#passkey-remove-name').textContent=`Remove “${passkey.display_name}” from this account? It will no longer be available for sign-in.`;setFormError('passkey-remove-error');$('#passkey-remove-dialog').showModal();form.elements.current_password.focus();}
    async function registerPasskey(event){event.preventDefault();if(!passkeySupported()){setFormError('passkey-error','Passkeys require a supported browser and HTTPS (localhost is allowed).');return;}const form=$('#passkey-register-form');if(!form.reportValidity())return;const submit=form.querySelector('[type="submit"]');submit.disabled=true;setFormError('passkey-error');$('#passkey-state').textContent='Confirming password…';try{const payload=Object.fromEntries(new FormData(form).entries());const {options}=await api('/api/v1/users/me/passkeys/register',{method:'POST',body:JSON.stringify({current_password:payload.current_password})});const publicKey={...options,challenge:decodeBase64url(options.challenge),user:{...options.user,id:decodeBase64url(options.user.id)},excludeCredentials:(options.excludeCredentials||[]).map((item)=>({...item,id:decodeBase64url(item.id)}))};$('#passkey-state').textContent='Follow your device prompt…';const credential=await navigator.credentials.create({publicKey});if(!credential)throw new Error('Passkey creation was cancelled.');$('#passkey-state').textContent='Saving passkey…';await api('/api/v1/users/me/passkeys/register/complete',{method:'POST',body:JSON.stringify({...passkeyPayload(credential,true),display_name:payload.display_name})});form.reset();form.elements.display_name.value='My Passkey';$('#passkey-dialog').close();await loadOwnPasskeys();showMessage($('#users-message'),'Passkey added.');}catch(error){setFormError('passkey-error',error.message);}finally{submit.disabled=false;$('#passkey-state').textContent='';}}
    async function removePasskey(event){event.preventDefault();const form=$('#passkey-remove-form');if(!form.reportValidity())return;const submit=form.querySelector('[type="submit"]');submit.disabled=true;setFormError('passkey-remove-error');try{await api(`/api/v1/users/me/passkeys/${Number(form.dataset.passkeyId)}/remove`,{method:'POST',body:JSON.stringify({current_password:form.elements.current_password.value})});$('#passkey-remove-dialog').close();form.reset();await loadOwnPasskeys();showMessage($('#users-message'),'Passkey removed.');}catch(error){setFormError('passkey-remove-error',error.message);}finally{submit.disabled=false;}}

    async function initializeApprovalSettings() {
        const card=$('#approval-policy-card');card.hidden=!can('settings.write');if(card.hidden)return;
        const company=$('#approval-policy-company');fillSelect(company,state.companyRecords,{placeholder:'Select company'});
        if(!company.value&&state.defaultCompanyId)company.value=String(state.defaultCompanyId);
        if(!company.value&&state.companyRecords[0])company.value=String(state.companyRecords[0].id);
        await loadApprovalPolicy();
    }
    async function loadApprovalPolicy() {
        const companyId=$('#approval-policy-company').value;if(!companyId){state.approvalApprovers=[];state.approvalPolicyRecords=[];renderApprovalPolicySteps([]);return;}
        const query=new URLSearchParams({company_id:companyId});const result=await api('/api/v1/approvals/policies?'+query);
        state.approvalApprovers=result.approvers||[];state.approvalPolicyRecords=result.policies||[];
        const policy=state.approvalPolicyRecords.find((item)=>item.document_type===$('#approval-policy-type').value);
        $('#approval-policy-name').value=policy?.name||( $('#approval-policy-type').value==='sales_order'?'Sales Order Approval':'Purchase Order Approval');
        $('#approval-policy-minimum').value=policy?.minimum_amount||'0';$('#approval-policy-active').checked=Boolean(policy?.active);
        renderApprovalPolicySteps(policy?.steps||[]);
    }
    function renderApprovalPolicySteps(steps) {
        const root=$('#approval-policy-steps');root.replaceChildren();
        for(const step of steps)appendApprovalPolicyStep(step.approver_user_id);
    }
    function appendApprovalPolicyStep(selectedId='') {
        const root=$('#approval-policy-steps');const row=document.createElement('div');row.className='approval-policy-step';
        const label=document.createElement('strong');label.textContent=`Layer ${root.children.length+1}`;
        const select=document.createElement('select');select.className='approval-approver-select';select.required=true;select.add(new Option('Select approver',''));
        for(const user of state.approvalApprovers)select.add(new Option(`${user.name} · ${user.login}`,String(user.id)));
        if(selectedId)select.value=String(selectedId);
        const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();[...root.children].forEach((item,index)=>item.querySelector('strong').textContent=`Layer ${index+1}`);});
        row.append(label,select,remove);root.append(row);
    }
    async function saveApprovalPolicy() {
        const companyId=Number($('#approval-policy-company').value);if(!companyId){showMessage($('#approval-policy-message'),'Select a company.',true);return;}
        const steps=$$('.approval-approver-select',$('#approval-policy-steps')).map((select)=>({approver_user_id:Number(select.value)}));
        if(steps.some((step)=>!step.approver_user_id)){showMessage($('#approval-policy-message'),'Choose an approver for every layer.',true);return;}
        try{const result=await api('/api/v1/approvals/policies',{method:'PUT',body:JSON.stringify({company_id:companyId,document_type:$('#approval-policy-type').value,name:$('#approval-policy-name').value.trim(),minimum_amount:$('#approval-policy-minimum').value||'0',active:$('#approval-policy-active').checked,steps})});state.approvalApprovers=result.approvers||[];state.approvalPolicyRecords=result.policies||[];await loadApprovalPolicy();showMessage($('#approval-policy-message'),'Approval policy saved.');}
        catch(error){showMessage($('#approval-policy-message'),error.message,true);}
    }
    async function loadApprovalInbox() {
        showMessage($('#approvals-message'));const result=await api('/api/v1/approvals');state.approvalRecords=result.records||[];renderApprovalInbox();
    }
    function renderApprovalInbox() {
        const body=$('#approval-rows');body.replaceChildren();
        if(!state.approvalRecords.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=7;cell.className='empty-cell';cell.textContent='No pending approval requests are assigned to you.';}
        for(const item of state.approvalRecords){const row=body.insertRow();const docCell=row.insertCell();const canOpen=item.document_type==='sales_order'?can('sales.read'):can('purchase.read');
            if(canOpen){const link=document.createElement('button');link.type='button';link.className='contact-link';link.textContent=`${item.document_name||'Document'} · ${item.partner_name||'Contact'}`;link.addEventListener('click',()=>openApprovalDocument(item));docCell.append(link);}else docCell.textContent=`${item.document_name||'Document'} · ${item.partner_name||'Contact'}`;
            row.insertCell().textContent=item.document_type==='sales_order'?'Sales Order':'Purchase Order';row.insertCell().textContent=item.company_name;row.insertCell().textContent=item.requester_name;row.insertCell().textContent=formatCurrencyAmount(item.amount,item.currency_code);row.insertCell().textContent=`Layer ${item.current_sequence}`;
            const actions=row.insertCell();for(const [decision,label] of [['approve','Approve'],['reject','Reject']]){const button=document.createElement('button');button.type='button';button.className=`button button-small ${decision==='approve'?'button-primary':''}`;button.textContent=label;button.addEventListener('click',()=>decideApprovalRequest(item.id,decision));actions.append(button);}
        }
        $('#approval-total').textContent=`${state.approvalRecords.length} pending approval${state.approvalRecords.length===1?'':'s'}`;
    }
    async function decideApprovalRequest(id,decision) {
        let comment='';if(decision==='reject'){comment=window.prompt('Reason for rejection (optional):','')??null;if(comment===null)return;}
        if(decision==='approve'&&!window.confirm('Approve this layer?'))return;
        try{const result=await api(`/api/v1/approvals/${id}/decision`,{method:'POST',body:JSON.stringify({decision,comment})});await loadApprovalInbox();showMessage($('#approvals-message'),result.request.status==='approved'?'All approval layers are complete. The requester must confirm the document again to continue.':result.request.status==='rejected'?'Request rejected. The requester must edit the document before sending it for approval again.':'Layer approved; the request moved to its next approver.');}
        catch(error){showMessage($('#approvals-message'),error.message,true);}
    }
    function openApprovalDocument(item) {
        if(item.document_type==='sales_order'&&can('sales.read')){selectPage('sales');$('#sales-company').value=String(item.company_id);openSalesOrder(Number(item.document_id));}
        else if(item.document_type==='purchase_order'&&can('purchase.read')){selectPage('purchase');$('#purchase-company').value=String(item.company_id);openPurchaseDetail(Number(item.document_id));}
    }

    async function loadCompanies() {
        showMessage($('#settings-message'));
        const payload = await api('/api/v1/companies');
        state.companyRecords = payload.records || [];
        state.companies = state.companyRecords.map((company) => ({ id: Number(company.id), name: company.name }));
        setHeader();
        renderCompanies();
        await loadDocumentPrefixes();
    }
    async function loadDocumentPrefixes(companyId = null) {
        const select = $('#document-prefix-company');
        fillSelect(select, state.companyRecords || [], { placeholder: 'Select company', keepValue: String(companyId || select.value || state.defaultCompanyId || state.companyRecords?.[0]?.id || '') });
        const form = $('#document-prefix-form');
        const selectedCompanyId = Number(select.value || 0);
        const save = form.querySelector('button[type="submit"]');
        if (!selectedCompanyId) {
            for (const field of ['sales_order','purchase_rfq','purchase_order','customer_invoice','vendor_bill']) form.elements[field].value = '';
            $$('input', form).forEach((input) => { input.disabled = true; });
            save.disabled = true;
            return;
        }
        showMessage($('#document-prefix-message'));
        const result = await api(`/api/v1/companies/${selectedCompanyId}/document-prefixes`);
        for (const field of ['sales_order','purchase_rfq','purchase_order','customer_invoice','vendor_bill']) form.elements[field].value = result.prefixes?.[field] ?? ({sales_order:'SO',purchase_rfq:'RFQ',purchase_order:'PO',customer_invoice:'INV',vendor_bill:'BILL'}[field]);
        const editable = can('settings.write');
        $$('input', form).forEach((input) => { input.disabled = !editable; });
        select.disabled = !editable && state.companyRecords.length < 2;
        save.disabled = !editable;
    }
    async function saveDocumentPrefixes(event) {
        event.preventDefault();
        const form = $('#document-prefix-form');
        if (!can('settings.write') || !form.reportValidity()) return;
        showMessage($('#document-prefix-message'));
        $('#document-prefix-save-state').textContent = 'Saving…';
        const companyId = Number($('#document-prefix-company').value || 0);
        const prefixes = Object.fromEntries(['sales_order','purchase_rfq','purchase_order','customer_invoice','vendor_bill'].map((field) => [field, form.elements[field].value.trim().toUpperCase()]));
        try {
            const result = await api(`/api/v1/companies/${companyId}/document-prefixes`, { method: 'PUT', body: JSON.stringify(prefixes) });
            for (const field of Object.keys(prefixes)) form.elements[field].value = result.prefixes[field];
            showMessage($('#document-prefix-message'), 'Document prefixes saved for this company.');
        } catch (error) {
            showMessage($('#document-prefix-message'), error.message, true);
        } finally {
            $('#document-prefix-save-state').textContent = '';
        }
    }
    function renderCompanies() {
        const list = $('#company-list'); list.replaceChildren();
        $('#company-count').textContent = `${state.companyRecords.length} compan${state.companyRecords.length === 1 ? 'y' : 'ies'}`;
        if (!state.companyRecords.length) {
            const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'No companies are available to this user.'; list.append(empty);
        }
        for (const company of state.companyRecords) {
            const button = document.createElement('button'); button.type = 'button';
            const title = document.createElement('span'); title.textContent = company.name;
            const meta = document.createElement('small'); meta.textContent = [company.legal_name, company.currency_code].filter(Boolean).join(' · ');
            button.append(title, meta); button.addEventListener('click', () => openCompany(Number(company.id)));
            list.append(button);
        }
        const summary = $('#current-company-summary'); summary.replaceChildren();
        const current = state.companyRecords.find((row) => Number(row.id) === Number(state.defaultCompanyId)) || state.companyRecords[0];
        if (current) {
            const name = document.createElement('strong'); name.textContent = current.name;
            const info = document.createElement('div'); info.textContent = [current.email, current.phone, current.currency_code, current.timezone].filter(Boolean).join(' · ');
            const link = document.createElement('button'); link.type = 'button'; link.className = 'text-link'; link.textContent = '→ Update Info';
            link.addEventListener('click', () => openCompany(Number(current.id)));
            summary.append(name, info, link);
        } else summary.textContent = 'Select a company to review its settings.';
    }
    const companyFields = [
        'name','legal_name','tax_id','street','street2','city','state','postal_code','country_code','phone','email','website',
        'email_domain','currency_code','timezone','color','external_code','x_account_url','facebook_account_url','github_account_url',
        'linkedin_account_url','youtube_account_url',
    ];
    const branchFields = ['parent_branch_id','partner_contact_id','name','legal_name','code','external_code','email','email_domain','phone','tax_id','website','street','street2','city','state','postal_code','country_code','currency_code','timezone','color','x_account_url','facebook_account_url','github_account_url','linkedin_account_url','youtube_account_url'];
    function countryOptions() {
        const names = new Intl.DisplayNames(['en'], { type: 'region' }); const rows = [];
        for (let first = 65; first <= 90; first++) for (let second = 65; second <= 90; second++) {
            const code = String.fromCharCode(first, second); const name = names.of(code);
            if (name && name !== code) rows.push({ value: code, name });
        }
        return rows.sort((a, b) => a.name.localeCompare(b.name));
    }
    function fillCountrySelect(select, placeholder, keepValue = '') {
        fillSelect(select, countryOptions(), { valueKey: 'value', placeholder, keepValue, label: (row) => row.name });
    }
    async function loadCompanySettingsOptions() {
        const result = await api('/api/v1/companies/options');
        state.companySettingsOptions = result.options;
    }
    function fillCompanyFields(form, record, { branch = false } = {}) {
        const country = form.elements.country_code; const currency = form.elements.currency_code; const timezone = form.elements.timezone;
        fillCountrySelect(country, branch ? 'Use company country' : 'Select country', record?.country_code || '');
        fillSelect(currency, state.companySettingsOptions.currencies || [], { valueKey: 'value', placeholder: branch ? 'Use company currency' : 'Select currency', label: (row) => `${row.value} · ${row.name}`, keepValue: record ? (record.currency_code || '') : (branch ? '' : 'BDT') });
        fillSelect(timezone, state.companySettingsOptions.timezones || [], { valueKey: 'value', placeholder: branch ? 'Use company timezone' : 'Select timezone', keepValue: record ? (record.timezone || '') : (branch ? '' : 'Asia/Dhaka') });
        for (const field of (branch ? branchFields : companyFields)) {
            if (!form.elements[field] || ['country_code', 'currency_code', 'timezone'].includes(field)) continue;
            if (record) setControl(form, field, record[field] ?? null);
        }
    }
    function setLogoPreview(kind, url = null) {
        const image = $(`#${kind}-logo-preview`); const placeholder = $(`#${kind}-logo-placeholder`); const remove = $(`#${kind}-logo-remove`);
        image.hidden = !url; placeholder.hidden = Boolean(url); image.src = url ? appUrl(url) : '';
        remove.hidden = !url;
    }
    function previewSelectedLogo(kind, input) {
        const file = input.files?.[0];
        if (!file) return;
        const url = URL.createObjectURL(file); setLogoPreview(kind, url);
    }
    async function uploadLogo(path, input) {
        const file = input.files?.[0];
        if (!file) return null;
        const formData = new FormData(); formData.append('logo', file);
        return api(path, { method: 'POST', body: formData });
    }
    function companyPayload(form) {
        const payload = {};
        for (const field of companyFields) {
            const value = form.elements[field]?.value.trim() || '';
            payload[field] = value === '' ? null : value;
        }
        if (payload.color !== null) payload.color = Number(payload.color);
        if (payload.country_code) payload.country_code = payload.country_code.toUpperCase();
        if (payload.currency_code) payload.currency_code = payload.currency_code.toUpperCase();
        return payload;
    }
    async function openCompany(id = null) {
        if (id && !can('settings.read')) return;
        if (!id && !can('settings.write')) return;
        const dialog = $('#company-dialog'); const form = $('#company-form');
        form.reset(); form.dataset.id = ''; state.currentCompany = null;
        $('#company-logo-file').value = ''; setLogoPreview('company');
        $('#company-contact-button').hidden = true;
        setFormError('company-form-error'); $('#company-save-state').textContent = '';
        $('#branch-list').replaceChildren(); $('#add-branch').disabled = true;
        switchTabs(dialog, 'company-general');
        try {
            await loadCompanySettingsOptions();
            let company = null;
            if (id) company = (await api(`/api/v1/companies/${id}`)).company;
            state.currentCompany = company; form.dataset.id = company ? String(company.id) : '';
            $('#company-dialog-title').textContent = company ? company.name : 'New Company';
            $('#company-contact-button').hidden = !company?.contact_id || !can('contacts.read');
            fillCompanyFields(form, company);
            setLogoPreview('company', company?.logo_url || null);
            await loadBranchContacts();
            if (company) {
                $('#add-branch').disabled = false;
                renderBranches(company.branches || []);
                $('#company-branches').hidden = false;
            } else $('#company-branches').hidden = true;
            setCompanyFormEditable(can('settings.write'));
            dialog.showModal();
        } catch (error) { setFormError('company-form-error', error.message); dialog.showModal(); }
    }
    function setCompanyFormEditable(editable) {
        const form = $('#company-form');
        $$('input,select,textarea', form).forEach((control) => { control.disabled = !editable; });
        $('#company-logo-remove').disabled = !editable; $('#company-logo-file').disabled = !editable;
        $('#add-branch').disabled = !editable || !state.currentCompany?.id;
        form.querySelector('button[type="submit"]').disabled = !editable;
    }
    async function loadBranchContacts() {
        const select = $('#branch-form').elements.partner_contact_id;
        if (!can('contacts.read')) { select.disabled = true; return; }
        try {
            const options = await api('/api/v1/contact-options');
            fillSelect(select, options.options.contacts, { placeholder: 'Auto-create linked contact', label: (row) => `${row.name} · ${row.contact_type}` });
        } catch { select.disabled = true; }
    }
    function renderBranches(branches) {
        const byId = new Map((branches || []).map((branch) => [Number(branch.id), branch]));
        const list = $('#branch-list'); list.replaceChildren();
        if (!branches.length) {
            const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'No branches have been added.'; list.append(empty); return;
        }
        for (const branch of branches) {
            const row = document.createElement('div'); row.className = 'branch-row';
            const details = document.createElement('div'); const name = document.createElement('strong'); name.textContent = branch.name;
            const parent = byId.get(Number(branch.parent_branch_id));
            const meta = document.createElement('small'); meta.textContent = [parent ? `↳ ${parent.name}` : 'Top-level', branch.external_code || branch.code, branch.city, branch.partner_name || `Contact #${branch.partner_contact_id || 'pending'}`].filter(Boolean).join(' · ');
            details.append(name, meta);
            const actions = document.createElement('div'); actions.className = 'branch-row-actions';
            if (branch.partner_contact_id && can('contacts.read')) {
                const contact = document.createElement('button'); contact.type = 'button'; contact.className = 'button button-small'; contact.textContent = 'Open Contact';
                contact.addEventListener('click', () => { $('#company-dialog').close(); openContact(Number(branch.partner_contact_id)); }); actions.append(contact);
            }
            if (can('settings.write')) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openBranch(branch));
                const archive = document.createElement('button'); archive.type = 'button'; archive.className = 'button button-small'; archive.textContent = 'Archive';
                archive.addEventListener('click', () => archiveBranch(Number(branch.id)));
                actions.append(edit, archive);
            }
            row.append(details, actions); list.append(row);
        }
    }
    function branchDescendantIds(branchId) {
        const descendants = new Set(); const pending = [Number(branchId)];
        while (pending.length) {
            const parentId = pending.pop();
            for (const item of (state.currentCompany?.branches || [])) if (Number(item.parent_branch_id) === parentId && !descendants.has(Number(item.id))) {
                descendants.add(Number(item.id)); pending.push(Number(item.id));
            }
        }
        return descendants;
    }
    function fillBranchParentOptions(branch, keepValue = '') {
        const ownId = Number(branch?.id || 0); const excluded = ownId ? branchDescendantIds(ownId) : new Set();
        if (ownId) excluded.add(ownId);
        const rows = (state.currentCompany?.branches || []).filter((item) => !excluded.has(Number(item.id)));
        fillSelect($('#branch-parent'), rows, { placeholder: 'Top-level branch', keepValue, label: (item) => item.name });
    }
    function renderBranchChildren(branchId) {
        const list = $('#branch-child-list'); list.replaceChildren();
        if (!branchId) { const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'Save this branch before adding sub-branches.'; list.append(empty); return; }
        const children = (state.currentCompany?.branches || []).filter((branch) => Number(branch.parent_branch_id) === Number(branchId));
        if (!children.length) { const empty = document.createElement('p'); empty.className = 'help-text'; empty.textContent = 'No branches have been added here.'; list.append(empty); }
        for (const branch of children) {
            const row = document.createElement('div'); row.className = 'branch-row';
            const details = document.createElement('div'); const name = document.createElement('strong'); name.textContent = branch.name;
            const meta = document.createElement('small'); meta.textContent = [branch.external_code || branch.code, branch.city, branch.partner_name || ''].filter(Boolean).join(' · '); details.append(name, meta);
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openBranch(branch));
            const archive = document.createElement('button'); archive.type = 'button'; archive.className = 'button button-small'; archive.textContent = 'Archive'; archive.addEventListener('click', () => archiveBranch(Number(branch.id)));
            const actions = document.createElement('div'); actions.className = 'branch-row-actions'; actions.append(edit, archive); row.append(details, actions); list.append(row);
        }
    }
    function openBranch(branch = null, parentId = null) {
        if (!can('settings.write') || !state.currentCompany?.id) return;
        const dialog = $('#branch-dialog'); const form = $('#branch-form');
        form.reset(); form.dataset.id = branch ? String(branch.id) : '';
        state.currentBranch = branch;
        setFormError('branch-form-error');
        $('#branch-dialog-title').textContent = branch ? `Edit ${branch.name}` : 'New Branch';
        fillCompanyFields(form, branch, { branch: true });
        fillBranchParentOptions(branch, parentId ?? branch?.parent_branch_id ?? '');
        $('#branch-logo-file').value = ''; setLogoPreview('branch', branch?.logo_url || null);
        $('#add-child-branch').disabled = !branch?.id;
        renderBranchChildren(branch?.id || null);
        switchTabs(dialog, 'branch-general');
        $('#branch-children').hidden = !branch?.id;
        if (branch?.partner_contact_id && ![...form.elements.partner_contact_id.options].some((option) => option.value === String(branch.partner_contact_id))) {
            form.elements.partner_contact_id.add(new Option(branch.partner_name || `Contact ${branch.partner_contact_id}`, String(branch.partner_contact_id)));
        }
        dialog.showModal();
    }
    function branchPayload(form) {
        const payload = {};
        for (const field of branchFields) {
            const value = form.elements[field]?.value.trim() || '';
            payload[field] = value === '' ? null : value;
            if (['parent_branch_id','partner_contact_id'].includes(field) && payload[field] !== null) payload[field] = Number(payload[field]);
        }
        if (payload.color !== null) payload.color = Number(payload.color);
        if (payload.country_code) payload.country_code = payload.country_code.toUpperCase();
        if (payload.currency_code) payload.currency_code = payload.currency_code.toUpperCase();
        return payload;
    }
    async function saveCompany(event) {
        event.preventDefault(); const form = $('#company-form'); if (!form.reportValidity()) return;
        setFormError('company-form-error'); $('#company-save-state').textContent = 'Saving…';
        try {
            const id = form.dataset.id;
            const result = await api(id ? `/api/v1/companies/${id}` : '/api/v1/companies', {
                method: id ? 'PATCH' : 'POST', body: JSON.stringify(companyPayload(form)),
            });
            let saved = result.company; let logoError = null;
            if ($('#company-logo-file').files?.[0]) {
                try { saved = (await uploadLogo(`/api/v1/companies/${saved.id}/logo`, $('#company-logo-file'))).company; $('#company-logo-file').value = ''; }
                catch (error) { logoError = error.message; }
            }
            state.currentCompany = saved; form.dataset.id = String(saved.id);
            $('#company-dialog-title').textContent = saved.name;
            $('#company-contact-button').hidden = !saved.contact_id || !can('contacts.read');
            setLogoPreview('company', saved.logo_url || null);
            $('#company-branches').hidden = false; $('#add-branch').disabled = false;
            renderBranches(saved.branches || []); await loadCompanies();
            if (logoError) setFormError('company-form-error', `Company details were saved, but the logo was not uploaded: ${logoError}`);
            else showMessage($('#settings-message'), id ? 'Company settings updated.' : 'Company created.');
        } catch (error) { setFormError('company-form-error', error.message); }
        finally { $('#company-save-state').textContent = ''; }
    }
    async function saveBranch(event) {
        event.preventDefault(); const form = $('#branch-form'); if (!form.reportValidity()) return;
        setFormError('branch-form-error');
        try {
            const id = form.dataset.id; const companyId = state.currentCompany.id;
            const result = await api(id ? `/api/v1/companies/${companyId}/branches/${id}` : `/api/v1/companies/${companyId}/branches`, {
                method: id ? 'PATCH' : 'POST', body: JSON.stringify(branchPayload(form)),
            });
            let savedBranch = result.branch; let logoError = null;
            if ($('#branch-logo-file').files?.[0]) {
                try { savedBranch = (await uploadLogo(`/api/v1/companies/${companyId}/branches/${savedBranch.id}/logo`, $('#branch-logo-file'))).branch; $('#branch-logo-file').value = ''; }
                catch (error) { logoError = error.message; }
            }
            const returnParentId = state.branchReturnParentId; state.branchReturnParentId = null;
            $('#branch-dialog').close();
            state.currentCompany = (await api(`/api/v1/companies/${companyId}`)).company;
            renderBranches(state.currentCompany.branches || []); await loadCompanies();
            if (returnParentId) {
                const parent = state.currentCompany.branches.find((branch) => Number(branch.id) === Number(returnParentId));
                if (parent) { openBranch(parent); switchTabs($('#branch-dialog'), 'branch-children'); }
            }
            if (logoError) showMessage($('#settings-message'), `Branch details were saved, but the logo was not uploaded: ${logoError}`, true);
        } catch (error) { setFormError('branch-form-error', error.message); }
    }
    async function archiveBranch(id) {
        if (!window.confirm('Archive this branch? Its linked contact and business history will remain.')) return;
        try {
            await api(`/api/v1/companies/${state.currentCompany.id}/branches/${id}`, { method: 'DELETE' });
            state.currentCompany = (await api(`/api/v1/companies/${state.currentCompany.id}`)).company;
            renderBranches(state.currentCompany.branches || []); await loadCompanies();
        } catch (error) { setFormError('company-form-error', error.message); }
    }
    async function removeCompanyLogo(kind) {
        const input = $(`#${kind}-logo-file`); const current = kind === 'company' ? state.currentCompany : state.currentBranch;
        if (input.files?.length) {
            input.value = ''; setLogoPreview(kind, current?.logo_url || null); return;
        }
        if (!current?.logo_path) { setLogoPreview(kind); return; }
        try {
            const companyId = Number(state.currentCompany.id);
            const path = kind === 'company' ? `/api/v1/companies/${companyId}/logo` : `/api/v1/companies/${companyId}/branches/${Number(current.id)}/logo`;
            const result = await api(path, { method: 'DELETE' });
            if (kind === 'company') state.currentCompany = result.company;
            else {
                state.currentCompany = (await api(`/api/v1/companies/${companyId}`)).company;
                state.currentBranch = state.currentCompany.branches.find((branch) => Number(branch.id) === Number(current.id)) || null;
            }
            setLogoPreview(kind, (kind === 'company' ? state.currentCompany : state.currentBranch)?.logo_url || null);
            showMessage($('#settings-message'), `${kind === 'company' ? 'Company' : 'Branch'} logo removed.`);
        } catch (error) { setFormError(kind === 'company' ? 'company-form-error' : 'branch-form-error', error.message); }
    }

    async function initializeDiscuss() {
        if (!can('discuss.read')) return;
        const requested = state.discussCompanyId || state.defaultCompanyId || '';
        const url = '/api/v1/discuss/options' + (requested ? `?company_id=${encodeURIComponent(requested)}` : '');
        const result = await api(url);
        state.discussOptions = result.options;
        fillSelect($('#discuss-company'), result.options.companies || [], { placeholder: 'Choose company', keepValue: result.options.company_id });
        state.discussCompanyId = Number($('#discuss-company').value || result.options.company_id || 0) || null;
        $('#discuss-company-note').textContent = `${(result.options.users || []).length} active users available for direct messages and channel membership.`;
        await loadDiscussOptions(state.discussCompanyId);
        await loadDiscussWorkspace();
    }

    async function loadDiscussOptions(companyId) {
        if (!companyId) return;
        const result = await api(`/api/v1/discuss/options?company_id=${encodeURIComponent(companyId)}`);
        state.discussOptions = result.options;
        state.discussCompanyId = Number(result.options.company_id);
        fillSelect($('#discuss-company'), result.options.companies || [], { placeholder: 'Choose company', keepValue: state.discussCompanyId });
        fillSelect($('#discuss-form-company'), result.options.companies || [], { placeholder: 'Choose company', keepValue: state.discussCompanyId });
        const people = result.options.users || [];
        $('#discuss-participants').replaceChildren(...people.map((person) => new Option(person.name, String(person.id))));
        $('#discuss-company-note').textContent = `${people.length} active users available for direct messages and channel membership.`;
    }

    async function loadDiscussWorkspace() {
        const companyId = Number($('#discuss-company').value || state.discussCompanyId || 0);
        if (!companyId) { $('#discuss-thread-list').textContent = 'No accessible company is available.'; return; }
        state.discussCompanyId = companyId;
        state.discussFilter = $('#discuss-filter').value;
        showMessage($('#discuss-message'));
        try {
            const result = await api(`/api/v1/discuss/conversations?company_id=${companyId}&filter=${encodeURIComponent(state.discussFilter)}`);
            state.discussConversations = result.conversations || [];
            renderDiscussWorkspace();
            if (state.discussCurrent && state.discussConversations.some((row) => Number(row.id) === Number(state.discussCurrent.id))) {
                renderDiscussConversation();
            } else if (state.discussCurrent && Number(state.discussCurrent.company_id) !== companyId) {
                state.discussCurrent = null; state.discussMessages = []; state.discussMembers = []; renderDiscussConversation();
            }
        } catch (error) { showMessage($('#discuss-message'), error.message, true); }
    }

    function renderDiscussWorkspace() {
        const list = $('#discuss-thread-list'); list.replaceChildren();
        if (!state.discussConversations.length) { const empty = document.createElement('p'); empty.className = 'empty-cell'; empty.textContent = state.discussFilter === 'starred' ? 'No starred conversations.' : state.discussFilter === 'history' ? 'History is empty.' : 'No conversations yet. Join a public channel or start a conversation.'; list.append(empty); return; }
        for (const row of state.discussConversations) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'discuss-thread';
            if (Number(state.discussCurrent?.id) === Number(row.id)) button.classList.add('active');
            const title = document.createElement('strong'); title.textContent = `${row.conversation_type === 'channel' ? '# ' : ''}${row.display_name || 'Direct message'}`;
            const badge = document.createElement('span'); badge.className = 'discuss-unread'; badge.textContent = Number(row.unread_count) > 99 ? '99+' : String(row.unread_count || ''); badge.hidden = Number(row.unread_count) < 1;
            const preview = document.createElement('small'); preview.textContent = row.last_message || (row.conversation_type === 'channel' ? `${row.privacy} channel · ${row.description || 'No description'}` : row.participant_names || 'Direct message');
            button.append(title, badge, preview); button.addEventListener('click', () => loadDiscussConversation(Number(row.id)).catch((error) => showMessage($('#discuss-message'), error.message, true))); list.append(button);
        }
    }

    async function loadDiscussConversation(id, beforeId = 0) {
        showMessage($('#discuss-message'));
        const result = await api(`/api/v1/discuss/conversations/${id}${beforeId ? `?before_id=${beforeId}` : ''}`);
        if (beforeId && Number(state.discussCurrent?.id) === id) {
            const existing = new Set(state.discussMessages.map((message) => Number(message.id)));
            state.discussMessages = [...(result.messages || []).filter((message) => !existing.has(Number(message.id))), ...state.discussMessages];
        } else {
            state.discussCurrent = result.conversation; state.discussMessages = result.messages || []; state.discussMembers = result.members || [];
        }
        state.discussHasOlder = Boolean(result.has_older);
        state.discussCurrent = result.conversation; state.discussMembers = result.members || state.discussMembers;
        renderDiscussWorkspace(); renderDiscussConversation();
        if (!beforeId && result.conversation.is_member) {
            await api(`/api/v1/discuss/conversations/${id}/read`, { method: 'POST', body: JSON.stringify({}) });
            await loadDiscussWorkspace();
        }
    }

    function renderDiscussConversation() {
        const conversation = state.discussCurrent;
        const empty = $('#discuss-chat-empty'); const messages = $('#discuss-messages'); messages.replaceChildren();
        $('#discuss-title').textContent = conversation ? `${conversation.conversation_type === 'channel' ? '# ' : ''}${conversation.display_name || 'Direct message'}` : 'Select a conversation';
        $('#discuss-description').textContent = conversation?.description || (conversation?.conversation_type === 'channel' ? `${conversation.privacy} company channel` : '');
        $('#discuss-members').textContent = conversation ? `Members: ${(state.discussMembers || []).map((member) => member.name).join(', ') || (conversation.privacy === 'public' ? 'Join to see members' : '—')}` : '';
        empty.hidden = Boolean(conversation);
        $('#discuss-star').hidden = !conversation?.is_member;
        $('#discuss-star').textContent = conversation?.starred ? '★ Starred' : '☆ Star';
        $('#discuss-archive').hidden = !conversation?.is_member;
        $('#discuss-archive').textContent = conversation?.archived ? 'Return to Inbox' : 'Move to History';
        $('#discuss-join').hidden = !conversation || conversation.conversation_type !== 'channel' || conversation.privacy !== 'public' || conversation.is_member;
        $('#discuss-compose').hidden = !conversation?.is_member || !can('discuss.write');
        $('#discuss-load-older').hidden = !conversation || !state.discussHasOlder;
        for (const message of state.discussMessages) {
            const article = document.createElement('article'); article.className = 'discuss-message';
            if (Number(message.author_user_id) === Number(state.user?.id)) article.classList.add('mine');
            const heading = document.createElement('div'); heading.className = 'discuss-message-head';
            const author = document.createElement('strong'); author.textContent = message.author_name || 'User';
            const date = document.createElement('time'); date.textContent = message.created_at || '';
            heading.append(author, date); const body = document.createElement('p'); body.className = 'discuss-message-body'; body.textContent = message.body || '';
            article.append(heading, body); messages.append(article);
        }
        if (conversation) messages.scrollTop = messages.scrollHeight;
    }

    function updateDiscussDialogMode() {
        const isChannel = $('#discuss-kind').value === 'channel';
        $('#discuss-channel-fields').hidden = !isChannel;
        $('#discuss-channel-name').required = isChannel;
        const participantLabel = $('#discuss-participants-wrap');
        if (participantLabel.firstChild) participantLabel.firstChild.textContent = isChannel ? 'Initial members ' : 'People ';
        $('#discuss-participants-help').textContent = isChannel ? 'Optional: invite active users with access to this company. Public channels remain discoverable by other company users.' : 'Select one or more active users with access to this company. Use Ctrl or Command to select several.';
    }

    async function openDiscussDialog() {
        if (!can('discuss.write')) return;
        const form = $('#discuss-form'); form.reset(); setFormError('discuss-form-error');
        try { await loadDiscussOptions(Number($('#discuss-company').value || state.discussCompanyId || 0)); }
        catch (error) { setFormError('discuss-form-error', error.message); }
        $('#discuss-form-company').value = String(state.discussCompanyId || ''); $('#discuss-kind').value = 'direct'; updateDiscussDialogMode(); $('#discuss-dialog').showModal();
    }

    async function saveDiscussConversation(event) {
        event.preventDefault(); const form = $('#discuss-form'); if (!form.reportValidity()) return; setFormError('discuss-form-error');
        const data = new FormData(form); const companyId = Number(data.get('company_id'));
        const ids = [...$('#discuss-participants').selectedOptions].map((option) => Number(option.value));
        const channel = data.get('kind') === 'channel';
        const payload = channel
            ? { company_id: companyId, name: String(data.get('name') || '').trim(), description: String(data.get('description') || '').trim(), privacy: String(data.get('privacy') || 'public'), member_ids: ids }
            : { company_id: companyId, participant_ids: ids };
        try {
            const result = await api(channel ? '/api/v1/discuss/channels' : '/api/v1/discuss/direct', { method: 'POST', body: JSON.stringify(payload) });
            $('#discuss-dialog').close(); $('#discuss-company').value = String(companyId); state.discussCompanyId = companyId; state.discussFilter = 'inbox'; $('#discuss-filter').value = 'inbox';
            await loadDiscussOptions(companyId); await loadDiscussWorkspace(); await loadDiscussConversation(Number(result.conversation.id));
        } catch (error) { setFormError('discuss-form-error', error.message); }
    }

    async function sendDiscussMessage(event) {
        event.preventDefault(); const conversation = state.discussCurrent; if (!conversation || !can('discuss.write')) return;
        const body = $('#discuss-message-body').value.trim(); if (!body) return;
        try {
            const result = await api(`/api/v1/discuss/conversations/${conversation.id}/messages`, { method: 'POST', body: JSON.stringify({ body }) });
            state.discussMessages.push(result.message); state.discussCurrent.archived = false; $('#discuss-message-body').value = ''; $('#discuss-filter').value = 'inbox'; state.discussFilter = 'inbox'; renderDiscussConversation(); await loadDiscussWorkspace();
        } catch (error) { showMessage($('#discuss-message'), error.message, true); }
    }

    async function joinDiscussChannel() {
        if (!state.discussCurrent || !can('discuss.write')) return;
        try { await api(`/api/v1/discuss/conversations/${state.discussCurrent.id}/join`, { method: 'POST', body: JSON.stringify({}) }); await loadDiscussConversation(Number(state.discussCurrent.id)); }
        catch (error) { showMessage($('#discuss-message'), error.message, true); }
    }

    async function setDiscussPreference(key, value) {
        if (!state.discussCurrent) return;
        try { await api(`/api/v1/discuss/conversations/${state.discussCurrent.id}/preference`, { method: 'POST', body: JSON.stringify({ key, value }) }); if (key === 'archived') { $('#discuss-filter').value = value ? 'history' : 'inbox'; state.discussFilter = $('#discuss-filter').value; } await loadDiscussWorkspace(); await loadDiscussConversation(Number(state.discussCurrent.id)); }
        catch (error) { showMessage($('#discuss-message'), error.message, true); }
    }

    function calendarDateInZone(date, timezone) {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
        const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    }
    function calendarCompany() { return Number($('#calendar-company').value || state.defaultCompanyId || 0); }
    async function loadCalendarOptions(companyId = calendarCompany()) {
        const query = companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
        const result = await api(`/api/v1/calendar/options${query}`); state.calendar.options = result.options || {};
        for (const company of state.calendar.options.companies || []) {
            const known = state.companies.find((row) => Number(row.id) === Number(company.id));
            if (known) known.name = company.name; else state.companies.push(company);
        }
        setHeader();
        fillSelect($('#calendar-company'), state.calendar.options.companies, { placeholder: 'Select company', keepValue: companyId || state.calendar.options.company?.id });
        fillSelect($('#calendar-event-company'), state.calendar.options.companies, { placeholder: 'Select company', keepValue: companyId || state.calendar.options.company?.id });
        fillSelect($('#calendar-event-organizer'), state.calendar.options.users || [], { placeholder: 'Select organizer', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        const contacts = state.calendar.options.contacts || [];
        fillSelect($('#calendar-event-contact'), contacts, { placeholder: 'No linked Contact', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
        fillAppointmentMultiSelect($('#calendar-event-users'), state.calendar.options.users || [], (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}`);
        fillAppointmentMultiSelect($('#calendar-event-contacts'), contacts, (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}`);
        const canLinkResources = can('appointments.read') || can('appointments.write');
        $('#calendar-event-resources-wrap').hidden = !canLinkResources;
        $('#calendar-event-resources').disabled = !canLinkResources;
        if (canLinkResources) fillAppointmentMultiSelect($('#calendar-event-resources'), state.calendar.options.resources || [], (row) => `${row.name} · capacity ${row.capacity}`);
        if ($('#calendar-event-timezone').value === '' || !$('#calendar-event-timezone').value) $('#calendar-event-timezone').value = state.calendar.options.company?.timezone || 'Asia/Dhaka';
        return state.calendar.options;
    }
    async function initializeCalendar(companyId = '') {
        if (!(can('calendar.read') || can('calendar.write'))) return;
        const selected = String(companyId || state.calendar.contactFilter?.companyId || $('#calendar-company').value || state.defaultCompanyId || state.companies[0]?.id || '');
        await loadCalendarOptions(selected);
        const company = state.calendar.options.company;
        if (!state.calendar.anchorDate) state.calendar.anchorDate = calendarDateInZone(new Date(), company?.timezone || 'Asia/Dhaka');
        $('#calendar-anchor').value = state.calendar.anchorDate;
        $('#calendar-view-select').value = state.calendar.view;
        $('#calendar-weekends').checked = state.calendar.showWeekends;
        await loadCalendarEvents();
    }
    function calendarDateObject(value) {
        const [year, month, day] = String(value).split('-').map(Number);
        return new Date(Date.UTC(year, month - 1, day, 12));
    }
    function calendarDateString(date) {
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
    }
    function calendarAddDays(value, days) {
        const date = calendarDateObject(value); date.setUTCDate(date.getUTCDate() + days); return calendarDateString(date);
    }
    function calendarMonday(value) {
        const date = calendarDateObject(value); date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7)); return calendarDateString(date);
    }
    function calendarPeriodBounds() {
        const anchor = state.calendar.anchorDate || calendarDateInZone(new Date(), state.calendar.options.company?.timezone || 'Asia/Dhaka');
        const date = calendarDateObject(anchor); const view = state.calendar.view;
        if (view === 'day') return { start: anchor, end: calendarAddDays(anchor, 1) };
        if (view === 'week') { const start = calendarMonday(anchor); return { start, end: calendarAddDays(start, 7) }; }
        if (view === 'year') { const start = `${date.getUTCFullYear()}-01-01`; return { start, end: `${date.getUTCFullYear() + 1}-01-01` }; }
        const monthStart = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-01`;
        const gridStart = calendarMonday(monthStart);
        const dayCount = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
        const offset = (calendarDateObject(monthStart).getUTCDay() + 6) % 7;
        const rows = Math.ceil((offset + dayCount) / 7);
        return { start: gridStart, end: calendarAddDays(gridStart, rows * 7) };
    }
    function calendarDateLabel(value, options) {
        return new Intl.DateTimeFormat(undefined, { ...options, timeZone: 'UTC' }).format(calendarDateObject(value));
    }
    function calendarPeriodLabel(bounds) {
        const view = state.calendar.view; const anchor = calendarDateObject(state.calendar.anchorDate);
        if (view === 'day') return calendarDateLabel(state.calendar.anchorDate, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        if (view === 'year') return String(anchor.getUTCFullYear());
        const last = calendarAddDays(bounds.end, -1);
        if (view === 'month') return calendarDateLabel(state.calendar.anchorDate, { month: 'long', year: 'numeric' });
        const sameYear = bounds.start.slice(0, 4) === last.slice(0, 4);
        const sameMonth = bounds.start.slice(0, 7) === last.slice(0, 7);
        if (sameMonth) return `${calendarDateLabel(bounds.start, { month: 'long' })} ${calendarDateLabel(bounds.start, { day: 'numeric' })}–${calendarDateLabel(last, { day: 'numeric', year: 'numeric' })}`;
        if (sameYear) return `${calendarDateLabel(bounds.start, { month: 'short', day: 'numeric' })} – ${calendarDateLabel(last, { month: 'short', day: 'numeric', year: 'numeric' })}`;
        return `${calendarDateLabel(bounds.start, { month: 'short', day: 'numeric', year: 'numeric' })} – ${calendarDateLabel(last, { month: 'short', day: 'numeric', year: 'numeric' })}`;
    }
    function shiftCalendarPeriod(direction) {
        if (!state.calendar.anchorDate) state.calendar.anchorDate = calendarDateInZone(new Date(), state.calendar.options.company?.timezone || 'Asia/Dhaka');
        const date = calendarDateObject(state.calendar.anchorDate); const view = state.calendar.view;
        if (view === 'day') date.setUTCDate(date.getUTCDate() + direction);
        else if (view === 'week') date.setUTCDate(date.getUTCDate() + (7 * direction));
        else if (view === 'year') date.setUTCFullYear(date.getUTCFullYear() + direction);
        else { date.setUTCDate(1); date.setUTCMonth(date.getUTCMonth() + direction); }
        state.calendar.anchorDate = calendarDateString(date); $('#calendar-anchor').value = state.calendar.anchorDate;
    }
    async function loadCalendarEvents() {
        const companyId = calendarCompany();
        if (!companyId) throw new Error('Choose an allowed company to use Calendar.');
        const bounds = calendarPeriodBounds();
        $('#calendar-from').value = bounds.start; $('#calendar-until').value = bounds.end;
        $('#calendar-period-label').textContent = calendarPeriodLabel(bounds);
        const query = new URLSearchParams({ company_id: String(companyId), from_date: bounds.start, until_date: bounds.end, status: $('#calendar-status').value });
        if (state.calendar.contactFilter) query.set('contact_id', String(state.calendar.contactFilter.id));
        const result = await api(`/api/v1/calendar/events?${query}`); state.calendar.events = result.events || [];
        renderCalendarEvents();
        $('#calendar-contact-filter').hidden = !state.calendar.contactFilter;
        if (state.calendar.contactFilter) $('#calendar-contact-filter-text').textContent = `Showing Calendar events linked to ${state.calendar.contactFilter.name}.`;
        showMessage($('#calendar-message'));
    }
    function calendarFormatDateTime(value, timezone) {
        if (!value) return '—';
        const date = new Date(`${String(value).replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(date);
    }
    function renderCalendarEvents() {
        const body = $('#calendar-event-rows'); body.replaceChildren();
        for (const event of state.calendar.events) {
            const row = body.insertRow(); row.insertCell().textContent = event.recurrent ? `${event.title} · Repeat #${event.recurrence_index}` : event.title;
            row.insertCell().textContent = event.all_day ? calendarDateLabel(event.start_date_local, { dateStyle: 'medium' }) : calendarFormatDateTime(event.starts_at_utc, event.timezone);
            row.insertCell().textContent = event.all_day ? calendarDateLabel(event.end_date_local, { dateStyle: 'medium' }) : calendarFormatDateTime(event.ends_at_utc, event.timezone);
            const contact = row.insertCell();
            if (event.contact_id && can('contacts.read')) { const open = document.createElement('button'); open.type = 'button'; open.className = 'text-link'; open.textContent = event.contact_name || 'Open Contact'; open.addEventListener('click', () => { selectPage('contacts'); openContact(Number(event.contact_id)); }); contact.append(open); }
            else contact.textContent = event.contact_name || '—';
            row.insertCell().textContent = event.organizer_name || '—';
            row.insertCell().textContent = `${event.user_attendee_count} user · ${event.contact_attendee_count} Contact`;
            row.insertCell().textContent = event.resource_names || '—';
            const source = row.insertCell();
            if (event.source_model === 'appointment' && can('appointments.read')) { const open = document.createElement('button'); open.type = 'button'; open.className = 'text-link'; open.textContent = `Appointment #${event.source_record_id}`; open.addEventListener('click', () => openCalendarAppointment(event).catch((error) => showMessage($('#calendar-message'), error.message, true))); source.append(open); }
            else source.textContent = event.source_model === 'appointment' ? `Appointment #${event.source_record_id}` : 'Manual';
            row.insertCell().textContent = String(event.status).replace('_', ' ');
            const rsvpCell = row.insertCell();
            if (event.my_response_status && event.status === 'scheduled') {
                const label = document.createElement('span');
                label.className = 'calendar-rsvp-state';
                label.textContent = ({ needs_action: 'Needs action', accepted: 'Accepted', tentative: 'Tentative', declined: 'Declined' })[event.my_response_status] || event.my_response_status;
                rsvpCell.append(label);
                const choices = [['accepted', 'Accept'], ['tentative', 'Tentative'], ['declined', 'Decline']];
                for (const [response, text] of choices) {
                    if (response === event.my_response_status) continue;
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small calendar-rsvp-button'; button.textContent = text;
                    button.addEventListener('click', async () => {
                        button.disabled = true;
                        try { const payload = { response_status: response }; if (event.recurrent) payload.occurrence_start_utc = event.occurrence_start_utc; await api(`/api/v1/calendar/events/${event.id}/response`, { method: 'POST', body: JSON.stringify(payload) }); await loadCalendarEvents(); }
                        catch (error) { button.disabled = false; showMessage($('#calendar-message'), error.message, true); }
                    });
                    rsvpCell.append(button);
                }
            } else rsvpCell.textContent = '—';
            const actions = row.insertCell();
            if (event.source_model === 'manual' && event.status === 'scheduled' && can('calendar.write')) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = event.recurrent ? 'Edit Series' : 'Edit'; edit.addEventListener('click', () => openCalendarEvent(Number(event.id))); actions.append(edit);
                if (event.recurrent) { const editOccurrence = document.createElement('button'); editOccurrence.type = 'button'; editOccurrence.className = 'button button-small'; editOccurrence.textContent = 'Edit This Occurrence'; editOccurrence.addEventListener('click', () => openCalendarEvent(Number(event.id), null, null, event).catch((error) => showMessage($('#calendar-message'), error.message, true))); actions.append(editOccurrence); }
            }
            if (event.source_model === 'manual' && event.status === 'scheduled' && can('calendar.write')) {
                for (const [status, baseLabel] of [['completed', 'Complete'], ['cancelled', 'Cancel']]) { const label = event.recurrent ? `${baseLabel} Series` : baseLabel; const confirmation = event.recurrent ? `${label} and all of its occurrences?` : `${baseLabel} this event?`; const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = label; button.addEventListener('click', async () => { if (!window.confirm(confirmation)) return; button.disabled = true; try { await api(`/api/v1/calendar/events/${event.id}/status`, { method: 'POST', body: JSON.stringify({ status }) }); await loadCalendarEvents(); } catch (error) { button.disabled = false; showMessage($('#calendar-message'), error.message, true); } }); actions.append(button); }
                if (event.recurrent) for (const [status, label] of [['completed','Complete This Occurrence'],['cancelled','Cancel This Occurrence']]) { const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = label; button.addEventListener('click', async () => { if (!window.confirm(`${label}?`)) return; button.disabled = true; try { await api(`/api/v1/calendar/events/${event.id}/occurrence/status`, { method: 'POST', body: JSON.stringify({ status, occurrence_start_utc: event.occurrence_start_utc }) }); await loadCalendarEvents(); } catch (error) { button.disabled = false; showMessage($('#calendar-message'), error.message, true); } }); actions.append(button); }
            }
            if (event.source_model === 'manual' && event.recurrent && event.is_occurrence_exception && can('calendar.write')) { const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'button button-small'; clear.textContent = 'Reset Occurrence'; clear.addEventListener('click', async () => { if (!window.confirm('Remove this occurrence change and restore the series details?')) return; clear.disabled = true; try { await api(`/api/v1/calendar/events/${event.id}/occurrence`, { method: 'DELETE', body: JSON.stringify({ occurrence_start_utc: event.occurrence_start_utc }) }); await loadCalendarEvents(); } catch (error) { clear.disabled = false; showMessage($('#calendar-message'), error.message, true); } }); actions.append(clear); }
        }
        if (!state.calendar.events.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 11; cell.className = 'empty-cell'; cell.textContent = state.calendar.contactFilter ? 'No Calendar events match this Contact and date range.' : 'No Calendar events match this company and date range.'; }
        $('#calendar-event-total').textContent = `${state.calendar.events.length} event${state.calendar.events.length === 1 ? '' : 's'}`;
        renderCalendarGrid();
    }
    function calendarEventLastDate(event) {
        if (event.all_day) return event.end_date_local;
        const startDate = event.starts_at_local.slice(0, 10); const endDate = event.ends_at_local.slice(0, 10);
        return event.ends_at_local.slice(11, 16) === '00:00' && endDate > startDate ? calendarAddDays(endDate, -1) : endDate;
    }
    function calendarEventsByDate(events, dates) {
        const map = new Map(dates.map((date) => [date, []]));
        if (!dates.length) return map;
        const first = dates[0]; const last = dates[dates.length - 1];
        for (const event of events) {
            let date = event.starts_at_local.slice(0, 10); const end = calendarEventLastDate(event);
            if (date < first) date = first;
            const stop = end > last ? last : end;
            while (date <= stop) { if (map.has(date)) map.get(date).push(event); date = calendarAddDays(date, 1); }
        }
        return map;
    }
    function calendarVisibleDates(start, end, weekends = state.calendar.showWeekends) {
        const dates = [];
        for (let date = start; date < end; date = calendarAddDays(date, 1)) {
            const day = calendarDateObject(date).getUTCDay();
            if (weekends || (day !== 0 && day !== 6)) dates.push(date);
        }
        return dates;
    }
    function openCalendarGridEvent(event) {
        if (event.source_model === 'appointment') {
            if (can('appointments.read')) openCalendarAppointment(event).catch((error) => showMessage($('#calendar-message'), error.message, true));
        } else if (can('calendar.write')) openCalendarEvent(Number(event.id)).catch((error) => showMessage($('#calendar-message'), error.message, true));
    }
    function calendarEventChip(event, includeTime = true) {
        const canOpen = (event.source_model === 'appointment' && can('appointments.read')) || (event.source_model === 'manual' && can('calendar.write'));
        const chip = document.createElement(canOpen ? 'button' : 'span');
        if (canOpen) { chip.type = 'button'; chip.addEventListener('click', () => openCalendarGridEvent(event)); }
        chip.className = `calendar-event-chip calendar-event-${event.status}${canOpen ? ' is-openable' : ''}`;
        chip.title = event.all_day
            ? `${event.title} · All Day · ${event.start_date_local}${event.end_date_local !== event.start_date_local ? ` – ${event.end_date_local}` : ''}`
            : `${event.title} · ${calendarFormatDateTime(event.starts_at_utc, event.timezone)} – ${calendarFormatDateTime(event.ends_at_utc, event.timezone)}`;
        chip.textContent = `${event.recurrent ? '↻ ' : ''}${event.all_day ? 'All day · ' : includeTime ? `${event.starts_at_local.slice(11, 16)} ` : ''}${event.title}`;
        return chip;
    }
    function calendarSetDate(date, view = state.calendar.view) {
        state.calendar.anchorDate = date; state.calendar.view = view;
        $('#calendar-anchor').value = date; $('#calendar-view-select').value = view;
        loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true));
    }
    function renderCalendarGrid() {
        const container = $('#calendar-grid-view'); container.replaceChildren();
        const bounds = calendarPeriodBounds(); const view = state.calendar.view;
        if (view === 'day' || view === 'week') {
            const weekStart = view === 'week' ? calendarMonday(state.calendar.anchorDate) : state.calendar.anchorDate;
            const allDates = view === 'week' ? Array.from({ length: 7 }, (_, index) => calendarAddDays(weekStart, index)) : [state.calendar.anchorDate];
            const dates = view === 'week' ? allDates.filter((date) => state.calendar.showWeekends || ![0, 6].includes(calendarDateObject(date).getUTCDay())) : allDates;
            const byDate = calendarEventsByDate(state.calendar.events, dates);
            const scroll = document.createElement('div'); scroll.className = 'calendar-time-scroll';
            const grid = document.createElement('div'); grid.className = 'calendar-time-grid';
            grid.style.gridTemplateColumns = `58px repeat(${dates.length}, minmax(125px, 1fr))`;
            const corner = document.createElement('div'); corner.className = 'calendar-time-corner'; grid.append(corner);
            for (const date of dates) { const head = document.createElement('div'); head.className = 'calendar-day-header'; head.textContent = calendarDateLabel(date, { weekday: 'short', month: 'short', day: 'numeric' }); grid.append(head); }
            const allDayAxis = document.createElement('div'); allDayAxis.className = 'calendar-all-day-axis'; allDayAxis.style.gridColumn = '1'; allDayAxis.textContent = 'All day'; grid.append(allDayAxis);
            const axis = document.createElement('div'); axis.className = 'calendar-time-axis';
            for (let hour = 0; hour < 24; hour++) { const mark = document.createElement('span'); mark.textContent = `${String(hour).padStart(2, '0')}:00`; mark.style.top = `${hour * 48 - 8}px`; axis.append(mark); }
            grid.append(axis);
            dates.forEach((date, index) => {
                const allDayColumn = document.createElement('div'); allDayColumn.className = 'calendar-all-day-column'; allDayColumn.style.gridColumn = String(index + 2);
                const dayEvents = byDate.get(date) || [];
                for (const event of dayEvents.filter((item) => item.all_day)) allDayColumn.append(calendarEventChip(event, false));
                grid.append(allDayColumn);
                const column = document.createElement('div'); column.className = 'calendar-day-column'; column.style.gridColumn = String(index + 2); column.dataset.date = date;
                const segments = dayEvents.filter((item) => !item.all_day).map((event) => {
                    const startDate = event.starts_at_local.slice(0, 10); const endDate = calendarEventLastDate(event);
                    const startMinute = startDate < date ? 0 : Number(event.starts_at_local.slice(11, 13)) * 60 + Number(event.starts_at_local.slice(14, 16));
                    const rawEndDate = event.ends_at_local.slice(0, 10);
                    let endMinute = endDate > date || (rawEndDate > date && event.ends_at_local.slice(11, 16) === '00:00') ? 1440 : Number(event.ends_at_local.slice(11, 13)) * 60 + Number(event.ends_at_local.slice(14, 16));
                    if (endMinute <= startMinute) endMinute = Math.min(1440, startMinute + 15);
                    return { event, startMinute, endMinute, lane: 0 };
                }).sort((left, right) => left.startMinute - right.startMinute || left.endMinute - right.endMinute || left.event.id - right.event.id);
                const laneEnds = [];
                for (const segment of segments) { let lane = laneEnds.findIndex((end) => end <= segment.startMinute); if (lane < 0) lane = laneEnds.length; laneEnds[lane] = segment.endMinute; segment.lane = lane; }
                const laneCount = Math.max(1, laneEnds.length);
                for (const segment of segments) {
                    const chip = calendarEventChip(segment.event, false); chip.classList.add('calendar-time-event');
                    chip.style.top = `${Math.round(segment.startMinute * 0.8)}px`; chip.style.height = `${Math.max(26, Math.round((segment.endMinute - segment.startMinute) * 0.8))}px`;
                    chip.style.left = `${(segment.lane * 100) / laneCount}%`; chip.style.width = `${100 / laneCount}%`; chip.textContent = `${segment.event.starts_at_local.slice(11, 16)} ${segment.event.title}`;
                    column.append(chip);
                }
                column.addEventListener('dblclick', (event) => {
                    if (!can('calendar.write') || event.target.closest('.calendar-time-event')) return;
                    const boundsRect = column.getBoundingClientRect(); const y = Math.max(0, event.clientY - boundsRect.top + column.scrollTop); const minute = Math.floor((y / 1152) * 1440 / 15) * 15;
                    openCalendarEvent(null, null, `${date}T${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`).catch((error) => showMessage($('#calendar-message'), error.message, true));
                });
                grid.append(column);
            });
            scroll.append(grid); container.append(scroll);
            const hint = document.createElement('p'); hint.className = 'calendar-view-hint'; hint.textContent = view === 'week' && !state.calendar.showWeekends ? 'Double-click an empty time slot to schedule an event. Weekends are hidden.' : 'Double-click an empty time slot to schedule an event.'; container.append(hint);
            return;
        }
        if (view === 'month') {
            const dates = calendarVisibleDates(bounds.start, bounds.end); const byDate = calendarEventsByDate(state.calendar.events, dates);
            const firstDate = calendarDateObject(bounds.start); const weekRows = Math.round((calendarDateObject(bounds.end).getTime() - firstDate.getTime()) / (7 * 86400000));
            const visibleWeekdayCount = state.calendar.showWeekends ? 7 : 5;
            const grid = document.createElement('div'); grid.className = 'calendar-month-grid'; grid.style.setProperty('--calendar-columns', String(visibleWeekdayCount)); grid.style.setProperty('--calendar-weeks', String(weekRows));
            const namesStart = '2026-01-05';
            for (let weekday = 0; weekday < visibleWeekdayCount; weekday++) { const date = calendarAddDays(namesStart, weekday); const header = document.createElement('div'); header.className = 'calendar-weekday'; header.textContent = calendarDateLabel(date, { weekday: 'short' }); grid.append(header); }
            for (let date = bounds.start; date < bounds.end; date = calendarAddDays(date, 1)) {
                const weekday = calendarDateObject(date).getUTCDay(); if (!state.calendar.showWeekends && [0, 6].includes(weekday)) continue;
                const day = calendarDateObject(date); const rowIndex = Math.floor((day.getTime() - firstDate.getTime()) / (7 * 86400000));
                const columnIndex = state.calendar.showWeekends ? (weekday + 6) % 7 : weekday - 1;
                const cell = document.createElement('section'); cell.className = `calendar-month-day${date.slice(0, 7) !== state.calendar.anchorDate.slice(0, 7) ? ' is-outside-month' : ''}`; cell.style.gridRow = String(rowIndex + 2); cell.style.gridColumn = String(columnIndex + 1);
                const dayButton = document.createElement('button'); dayButton.type = 'button'; dayButton.className = 'calendar-month-day-number'; dayButton.textContent = String(day.getUTCDate()); dayButton.setAttribute('aria-label', `Open ${calendarDateLabel(date, { dateStyle: 'full' })}`); dayButton.addEventListener('click', () => calendarSetDate(date, 'day')); cell.append(dayButton);
                const list = document.createElement('div'); list.className = 'calendar-month-events';
                for (const event of byDate.get(date) || []) list.append(calendarEventChip(event));
                if (!list.childElementCount) { const add = document.createElement('button'); add.type = 'button'; add.className = 'calendar-empty-day'; add.setAttribute('aria-label', `Open ${calendarDateLabel(date, { dateStyle: 'full' })}`); add.addEventListener('click', () => calendarSetDate(date, 'day')); cell.append(add); }
                cell.append(list); grid.append(cell);
            }
            container.append(grid); return;
        }
        const year = calendarDateObject(state.calendar.anchorDate).getUTCFullYear();
        const yearStart = `${year}-01-01`; const yearEnd = `${year + 1}-01-01`; const allDates = calendarVisibleDates(yearStart, yearEnd, true); const byDate = calendarEventsByDate(state.calendar.events, allDates);
        const yearGrid = document.createElement('div'); yearGrid.className = 'calendar-year-grid';
        for (let monthIndex = 0; monthIndex < 12; monthIndex++) {
            const monthStart = `${year}-${String(monthIndex + 1).padStart(2, '0')}-01`; const monthDate = calendarDateObject(monthStart);
            const nextMonth = new Date(Date.UTC(year, monthIndex + 1, 1, 12));
            const totalDays = new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate(); const offset = (monthDate.getUTCDay() + 6) % 7; const weeks = Math.ceil((offset + totalDays) / 7);
            const monthCard = document.createElement('section'); monthCard.className = 'calendar-mini-month';
            const monthButton = document.createElement('button'); monthButton.type = 'button'; monthButton.className = 'calendar-mini-month-title'; monthButton.textContent = calendarDateLabel(monthStart, { month: 'long' }); monthButton.addEventListener('click', () => calendarSetDate(monthStart, 'month')); monthCard.append(monthButton);
            const weekdays = state.calendar.showWeekends ? [1, 2, 3, 4, 5, 6, 0] : [1, 2, 3, 4, 5]; const grid = document.createElement('div'); grid.className = 'calendar-mini-days'; grid.style.setProperty('--calendar-columns', String(weekdays.length)); grid.style.setProperty('--calendar-weeks', String(weeks));
            const namesStart = '2026-01-05';
            for (const weekday of weekdays) { const date = calendarAddDays(namesStart, weekday - 1); const head = document.createElement('span'); head.className = 'calendar-mini-weekday'; head.textContent = calendarDateLabel(date, { weekday: 'narrow' }); grid.append(head); }
            for (let dayNumber = 1; dayNumber <= totalDays; dayNumber++) {
                const date = `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`; const dateObject = calendarDateObject(date); const week = Math.floor((offset + dayNumber - 1) / 7); const weekday = (dateObject.getUTCDay() + 6) % 7; if (!state.calendar.showWeekends && weekday > 4) continue;
                const cell = document.createElement('button'); cell.type = 'button'; cell.className = 'calendar-mini-day'; cell.style.gridRow = String(week + 2); cell.style.gridColumn = String(state.calendar.showWeekends ? weekday + 1 : weekday + 1); cell.setAttribute('aria-label', `${calendarDateLabel(date, { dateStyle: 'full' })}${byDate.get(date)?.length ? `, ${byDate.get(date).length} events` : ''}`);
                const number = document.createElement('span'); number.textContent = String(dayNumber); cell.append(number);
                const count = byDate.get(date)?.length || 0; if (count) { const badge = document.createElement('span'); badge.className = 'calendar-mini-count'; badge.textContent = String(count); cell.append(badge); }
                cell.addEventListener('click', () => calendarSetDate(date, 'month')); grid.append(cell);
            }
            monthCard.append(grid); yearGrid.append(monthCard);
        }
        container.append(yearGrid);
    }
    function calendarAddLocalMinutes(value, minutes) {
        const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(value));
        if (!match) return value;
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]) + minutes));
        return `${calendarDateString(date)}T${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')}`;
    }
    function calendarEventAnchorDate() {
        return $('#calendar-event-all-day').checked ? $('#calendar-event-start-date').value : $('#calendar-event-starts').value.slice(0, 10);
    }
    function calendarIsoWeekday(dateValue) {
        if (!dateValue) return 1;
        const day = new Date(`${dateValue}T00:00:00Z`).getUTCDay();
        return day === 0 ? 7 : day;
    }
    function setCalendarRecurrenceDefaults() {
        const dateValue = calendarEventAnchorDate();
        if (!dateValue) return;
        const anchor = new Date(`${dateValue}T00:00:00Z`);
        const isoWeekday = calendarIsoWeekday(dateValue);
        const weekOption = [...$('#calendar-event-weekdays').options].find((option) => Number(option.value) === isoWeekday);
        if (weekOption && ![...$('#calendar-event-weekdays').selectedOptions].length) weekOption.selected = true;
        if (!$('#calendar-event-month-day').value) $('#calendar-event-month-day').value = String(anchor.getUTCDate());
        $('#calendar-event-month-weekday').value = String(isoWeekday);
        const nextWeek = new Date(anchor); nextWeek.setUTCDate(nextWeek.getUTCDate() + 7);
        const ordinal = nextWeek.getUTCMonth() === anchor.getUTCMonth() ? Math.ceil(anchor.getUTCDate() / 7) : -1;
        $('#calendar-event-month-ordinal').value = String(ordinal);
    }
    function toggleCalendarRecurrence() {
        const enabled = $('#calendar-event-recurrent').checked;
        const frequency = $('#calendar-event-recurrence-frequency').value;
        const monthlyBy = $('#calendar-event-month-pattern').value;
        const endType = $('#calendar-event-recurrence-end-type').value;
        $('#calendar-event-recurrence-fields').hidden = !enabled;
        for (const selector of ['#calendar-event-recurrence-interval', '#calendar-event-recurrence-frequency', '#calendar-event-recurrence-end-type']) {
            $(selector).disabled = !enabled;
            $(selector).required = enabled;
        }
        const weekly = enabled && frequency === 'weekly';
        $('#calendar-event-weekdays-wrap').hidden = !weekly;
        $('#calendar-event-weekdays').disabled = !weekly;
        $('#calendar-event-weekdays').required = weekly;
        const monthly = enabled && frequency === 'monthly';
        $('#calendar-event-month-pattern-wrap').hidden = !monthly;
        $('#calendar-event-month-pattern').disabled = !monthly;
        $('#calendar-event-month-pattern').required = monthly;
        const monthByDay = monthly && monthlyBy === 'day';
        $('#calendar-event-month-day-wrap').hidden = !monthByDay;
        $('#calendar-event-month-day').disabled = !monthByDay;
        $('#calendar-event-month-day').required = monthByDay;
        const monthByWeekday = monthly && monthlyBy === 'weekday';
        $('#calendar-event-month-weekday-wrap').hidden = !monthByWeekday;
        $('#calendar-event-month-ordinal-wrap').hidden = !monthByWeekday;
        $('#calendar-event-month-weekday').disabled = !monthByWeekday;
        $('#calendar-event-month-ordinal').disabled = !monthByWeekday;
        $('#calendar-event-month-weekday').required = monthByWeekday;
        $('#calendar-event-month-ordinal').required = monthByWeekday;
        const countEnd = enabled && endType === 'count';
        $('#calendar-event-recurrence-count-wrap').hidden = !countEnd;
        $('#calendar-event-recurrence-count').disabled = !countEnd;
        $('#calendar-event-recurrence-count').required = countEnd;
        const dateEnd = enabled && endType === 'date';
        $('#calendar-event-recurrence-until-wrap').hidden = !dateEnd;
        $('#calendar-event-recurrence-until').disabled = !dateEnd;
        $('#calendar-event-recurrence-until').required = dateEnd;
    }
    async function openCalendarEvent(id = null, contact = null, selectedStart = null, occurrence = null) {
        if (!can('calendar.write')) return;
        const form = $('#calendar-event-form'); form.reset(); form.dataset.id = id ? String(id) : ''; form.dataset.occurrenceStart = ''; setFormError('calendar-event-error'); $('#calendar-event-title').textContent = id ? 'Edit Event' : 'New Event';
        $('#calendar-event-occurrence-note').hidden = true; $('#calendar-event-recurrent-wrap').hidden = false; $('#calendar-event-recurrent').disabled = false; $('#calendar-event-save-button').textContent = id ? 'Save Event' : 'Save Event';
        const companyId = String(contact?.company_id || calendarCompany() || state.defaultCompanyId || '');
        await loadCalendarOptions(companyId); $('#calendar-event-company').value = companyId;
        const company = state.calendar.options.company; $('#calendar-event-timezone').value = company?.timezone || 'Asia/Dhaka';
        $('#calendar-event-contact').value = contact?.id ? String(contact.id) : '';
        const currentUser = Number(state.user?.id || 0); if (state.calendar.options.users?.some((row) => Number(row.id) === currentUser)) { $('#calendar-event-organizer').value = String(currentUser); $('#calendar-event-users').querySelector(`option[value="${currentUser}"]`).selected = true; }
        const starts = new Date(Date.now() + 60 * 60 * 1000); starts.setMinutes(Math.ceil(starts.getMinutes() / 15) * 15, 0, 0); const localInput = (date) => `${calendarDateInZone(date, $('#calendar-event-timezone').value)}T${new Intl.DateTimeFormat('en-GB', { timeZone: $('#calendar-event-timezone').value, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date)}`;
        form.elements.starts_at_local.value = selectedStart ? selectedStart.slice(0, 16) : localInput(starts); form.elements.ends_at_local.value = calendarAddLocalMinutes(form.elements.starts_at_local.value, 60);
        if (id) {
            const result = await api(`/api/v1/calendar/events/${id}`); const event = result.event;
            if (event.source_model !== 'manual') throw new Error('Appointment events are edited from Appointments.');
            await loadCalendarOptions(String(event.company_id)); form.dataset.id = String(event.id);
            for (const key of ['company_id','title','starts_at_local','ends_at_local','timezone','organizer_user_id','contact_id','location','description']) if (form.elements[key]) form.elements[key].value = event[key] ?? '';
            for (const option of $('#calendar-event-users').options) option.selected = event.attendee_user_ids.includes(Number(option.value));
            for (const option of $('#calendar-event-contacts').options) option.selected = event.attendee_contact_ids.includes(Number(option.value));
            for (const option of $('#calendar-event-resources').options) option.selected = (event.resource_ids || []).includes(Number(option.value));
            $('#calendar-event-all-day').checked = Boolean(event.all_day);
            $('#calendar-event-start-date').value = event.start_date_local || '';
            $('#calendar-event-end-date').value = event.end_date_local || '';
            $('#calendar-event-recurrent').checked = Boolean(event.recurrent);
            $('#calendar-event-recurrence-frequency').value = event.recurrence_frequency || 'daily';
            $('#calendar-event-recurrence-interval').value = String(event.recurrence_interval || 1);
            $('#calendar-event-month-pattern').value = event.recurrence_month_by || 'day';
            $('#calendar-event-month-day').value = event.recurrence_day ?? '';
            $('#calendar-event-month-weekday').value = String(event.recurrence_weekday || 1);
            $('#calendar-event-month-ordinal').value = String(event.recurrence_week_ordinal ?? 1);
            $('#calendar-event-recurrence-end-type').value = event.recurrence_end_type || 'forever';
            $('#calendar-event-recurrence-count').value = event.recurrence_count ?? '';
            $('#calendar-event-recurrence-until').value = event.recurrence_until_date || '';
            for (const option of $('#calendar-event-weekdays').options) option.selected = (event.recurrence_weekdays || []).includes(Number(option.value));
            $('#calendar-event-title').textContent = event.recurrent ? 'Edit Recurring Series' : 'Edit Event';
            if (occurrence && event.recurrent && occurrence.occurrence_start_utc) {
                form.dataset.occurrenceStart = occurrence.occurrence_start_utc;
                for (const key of ['title','timezone','organizer_user_id','contact_id','location','description']) if (Object.prototype.hasOwnProperty.call(occurrence,key) && form.elements[key]) form.elements[key].value = occurrence[key] ?? '';
                form.elements.starts_at_local.value = occurrence.starts_at_local;
                form.elements.ends_at_local.value = occurrence.ends_at_local;
                $('#calendar-event-all-day').checked = Boolean(occurrence.all_day);
                $('#calendar-event-start-date').value = occurrence.start_date_local || '';
                $('#calendar-event-end-date').value = occurrence.end_date_local || '';
                const occurrenceUsers = occurrence.attendee_user_ids || event.attendee_user_ids;
                const occurrenceContacts = occurrence.attendee_contact_ids || event.attendee_contact_ids;
                const occurrenceResources = occurrence.resource_ids || event.resource_ids;
                for (const option of $('#calendar-event-users').options) option.selected = occurrenceUsers.includes(Number(option.value));
                for (const option of $('#calendar-event-contacts').options) option.selected = occurrenceContacts.includes(Number(option.value));
                for (const option of $('#calendar-event-resources').options) option.selected = occurrenceResources.includes(Number(option.value));
                $('#calendar-event-recurrent-wrap').hidden = true; $('#calendar-event-recurrent').checked = false; $('#calendar-event-recurrent').disabled = true;
                $('#calendar-event-occurrence-note').hidden = false; $('#calendar-event-title').textContent = 'Edit This Occurrence'; $('#calendar-event-save-button').textContent = 'Save This Event Only';
            }
        }
        toggleCalendarAllDay();
        if (!id) setCalendarRecurrenceDefaults();
        toggleCalendarRecurrence();
        $('#calendar-event-dialog').showModal();
    }
    function toggleCalendarAllDay() {
        const allDay = $('#calendar-event-all-day').checked;
        $('#calendar-event-started-at-wrap').hidden = allDay; $('#calendar-event-ended-at-wrap').hidden = allDay;
        $('#calendar-event-start-date-wrap').hidden = !allDay; $('#calendar-event-end-date-wrap').hidden = !allDay;
        $('#calendar-event-starts').disabled = allDay; $('#calendar-event-ends').disabled = allDay;
        $('#calendar-event-starts').required = !allDay; $('#calendar-event-ends').required = !allDay;
        $('#calendar-event-start-date').disabled = !allDay; $('#calendar-event-end-date').disabled = !allDay;
        $('#calendar-event-start-date').required = allDay; $('#calendar-event-end-date').required = allDay;
        if (allDay && !$('#calendar-event-start-date').value) $('#calendar-event-start-date').value = $('#calendar-event-starts').value.slice(0, 10);
        if (allDay && !$('#calendar-event-end-date').value) $('#calendar-event-end-date').value = $('#calendar-event-start-date').value;
    }
    async function saveCalendarEvent(event) {
        event.preventDefault(); const form = $('#calendar-event-form'); if (!form.reportValidity()) return;
        setFormError('calendar-event-error'); $('#calendar-event-save-state').textContent = 'Saving…';
        const payload = Object.fromEntries(new FormData(form).entries()); payload.company_id = Number(payload.company_id); payload.organizer_user_id = Number(payload.organizer_user_id); payload.contact_id = payload.contact_id ? Number(payload.contact_id) : null;
        payload.all_day = $('#calendar-event-all-day').checked;
        payload.recurrent = $('#calendar-event-recurrent').checked;
        if (payload.recurrent) {
            payload.recurrence_frequency = $('#calendar-event-recurrence-frequency').value;
            payload.recurrence_interval = Number($('#calendar-event-recurrence-interval').value);
            payload.recurrence_end_type = $('#calendar-event-recurrence-end-type').value;
            if (payload.recurrence_frequency === 'weekly') payload.recurrence_weekdays = [...$('#calendar-event-weekdays').selectedOptions].map((option) => Number(option.value));
            if (payload.recurrence_frequency === 'monthly') {
                payload.recurrence_month_by = $('#calendar-event-month-pattern').value;
                if (payload.recurrence_month_by === 'day') payload.recurrence_day = Number($('#calendar-event-month-day').value);
                else { payload.recurrence_weekday = Number($('#calendar-event-month-weekday').value); payload.recurrence_week_ordinal = Number($('#calendar-event-month-ordinal').value); }
            }
            if (payload.recurrence_end_type === 'count') payload.recurrence_count = Number($('#calendar-event-recurrence-count').value);
            if (payload.recurrence_end_type === 'date') payload.recurrence_until_date = $('#calendar-event-recurrence-until').value;
        } else {
            for (const key of ['recurrence_frequency','recurrence_interval','recurrence_weekdays','recurrence_month_by','recurrence_day','recurrence_weekday','recurrence_week_ordinal','recurrence_end_type','recurrence_count','recurrence_until_date']) delete payload[key];
        }
        if (payload.all_day) { payload.start_date = $('#calendar-event-start-date').value; payload.end_date = $('#calendar-event-end-date').value; delete payload.starts_at_local; delete payload.ends_at_local; }
        else { delete payload.start_date; delete payload.end_date; }
        payload.attendee_user_ids = [...$('#calendar-event-users').selectedOptions].map((option) => Number(option.value)); payload.attendee_contact_ids = [...$('#calendar-event-contacts').selectedOptions].map((option) => Number(option.value));
        if (!$('#calendar-event-resources').disabled) payload.resource_ids = [...$('#calendar-event-resources').selectedOptions].map((option) => Number(option.value));
        const id = Number(form.dataset.id); const occurrenceStart = form.dataset.occurrenceStart || ''; if (occurrenceStart) payload.occurrence_start_utc = occurrenceStart;
        const path = occurrenceStart ? `/api/v1/calendar/events/${id}/occurrence` : id ? `/api/v1/calendar/events/${id}` : '/api/v1/calendar/events';
        try { await api(path, { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); $('#calendar-event-dialog').close(); $('#calendar-company').value = String(payload.company_id); state.calendar.contactFilter = null; state.calendar.anchorDate = (payload.all_day ? payload.start_date : payload.starts_at_local).slice(0, 10); if (!id) state.calendar.view = 'day'; await initializeCalendar(String(payload.company_id)); showMessage($('#calendar-message'), occurrenceStart ? 'Calendar occurrence saved.' : 'Calendar event saved.'); }
        catch (error) { setFormError('calendar-event-error', error.message); } finally { $('#calendar-event-save-state').textContent = ''; }
    }
    function appointmentCompany() { return Number($('#appointments-company').value || state.defaultCompanyId || 0); }
    async function openCalendarAppointment(event) {
        if (!event?.source_record_id || !can('appointments.read')) return;
        await loadAppointmentOptions(Number(event.company_id));
        $('#appointments-company').value = String(event.company_id);
        $('#appointments-status').value = 'all';
        state.appointments.view = 'bookings';
        state.appointments.contactFilter = null;
        state.appointments.bookingFilter = { id: Number(event.source_record_id) };
        selectPage('appointments');
    }
    function mergeAppointmentCompanies(companies) {
        for (const company of companies || []) { const old = state.companies.find((row) => Number(row.id) === Number(company.id)); if (old) old.name = company.name; else state.companies.push(company); }
    }
    function fillAppointmentMultiSelect(select, rows, label) {
        const selected = new Set([...select.selectedOptions].map((option) => option.value)); select.replaceChildren();
        for (const row of rows || []) { const option = new Option(label(row), String(row.id)); option.selected = selected.has(option.value); select.add(option); }
    }
    async function loadAppointmentOptions(companyId = appointmentCompany()) {
        const query = companyId ? `?company_id=${encodeURIComponent(companyId)}` : '';
        const result = await api(`/api/v1/appointments/options${query}`); state.appointments.options = result.options || {}; mergeAppointmentCompanies(result.options?.companies || []);setHeader();
        const companySelects = ['#appointments-company', '#appointment-type-company', '#appointment-booking-company', '#appointment-resource-company'];
        for (const selector of companySelects) fillSelect($(selector), state.appointments.options.companies, { placeholder: 'Select company', keepValue: companyId || state.appointments.options.company_id });
        fillAppointmentMultiSelect($('#appointment-type-users'), state.appointments.options.users, (row) => row.name);
        fillAppointmentMultiSelect($('#appointment-type-resources'), state.appointments.options.resources, (row) => `${row.name} · capacity ${row.capacity}`);
        fillSelect($('#appointment-booking-contact'), state.appointments.options.contacts, { placeholder: 'No linked Contact', label: (row) => `${row.name}${row.email ? ` · ${row.email}` : ''}` });
    }
    async function initializeAppointments() {
        await loadAppointmentOptions();
        const company = appointmentCompany(); if (!company) throw new Error('Choose an allowed company to use Appointments.');
        if (state.appointments.view === 'types') await loadAppointmentTypes();
        else if (state.appointments.view === 'resources') await loadAppointmentResources();
        else await loadAppointmentBookings();
        updateAppointmentView();
    }
    function updateAppointmentView() {
        for (const view of ['bookings', 'types', 'resources']) {
            $(`#appointments-${view}-view`).hidden = state.appointments.view !== view;
            $(`#appointments-view-${view}`).classList.toggle('active', state.appointments.view === view);
        }
        const filterNotice = $('#appointments-contact-filter'); filterNotice.hidden = !state.appointments.contactFilter; filterNotice.replaceChildren();
        if (state.appointments.contactFilter) { const label = document.createElement('span'); label.textContent = `Showing bookings linked to ${state.appointments.contactFilter.name}. `; const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'button button-small'; clear.textContent = 'Clear filter'; clear.addEventListener('click', () => { state.appointments.contactFilter = null; loadAppointmentBookings().catch((error) => showMessage($('#appointments-message'), error.message, true)); }); filterNotice.append(label, clear); }
        const bookingNotice = $('#appointments-booking-filter'); bookingNotice.hidden = !state.appointments.bookingFilter; bookingNotice.replaceChildren();
        if (state.appointments.bookingFilter) { const label = document.createElement('span'); label.textContent = `Showing Calendar-linked booking #${state.appointments.bookingFilter.id}. `; const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'button button-small'; clear.textContent = 'Show all bookings'; clear.addEventListener('click', () => { state.appointments.bookingFilter = null; loadAppointmentBookings().catch((error) => showMessage($('#appointments-message'), error.message, true)); }); bookingNotice.append(label, clear); }
        const action = $('#page-actions').querySelector('button');
        if (action) action.textContent = state.appointments.view === 'types' ? 'New Appointment Type' : state.appointments.view === 'resources' ? 'New Resource' : 'New Booking';
    }
    async function loadAppointmentBookings({ append = false } = {}) {
        const company = appointmentCompany(); const query = new URLSearchParams({ company_id: String(company), status: $('#appointments-status').value, limit: '100', offset: String(append ? state.appointments.bookingOffset : 0) });
        if (state.appointments.contactFilter) query.set('contact_id', String(state.appointments.contactFilter.id));
        if (state.appointments.bookingFilter) query.set('booking_id', String(state.appointments.bookingFilter.id));
        const result = await api(`/api/v1/appointments/bookings?${query}`); const rows = result.bookings || [];
        state.appointments.bookings = append ? state.appointments.bookings.concat(rows) : rows;
        state.appointments.bookingTotal = Number(result.total ?? rows.length);
        state.appointments.bookingOffset = append ? state.appointments.bookingOffset + rows.length : rows.length;
        renderAppointmentBookings();
    }
    function renderAppointmentBookings() {
        const body = $('#appointment-booking-rows'); body.replaceChildren();
        for (const booking of state.appointments.bookings) {
            const row = body.insertRow(); row.insertCell().textContent = `${booking.appointment_type} · ${booking.booking_name}`;
            const contactCell = row.insertCell();
            if (booking.contact_id && can('contacts.read')) { const contact = document.createElement('button'); contact.type = 'button'; contact.className = 'text-link'; contact.textContent = booking.contact_name || booking.booking_name; contact.addEventListener('click', () => { selectPage('contacts'); openContact(Number(booking.contact_id)); }); contactCell.append(contact); }
            else contactCell.textContent = booking.contact_name || '—';
            const start = new Date(`${booking.starts_at_utc.replace(' ', 'T')}Z`); row.insertCell().textContent = Number.isNaN(start.getTime()) ? booking.starts_at_utc : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: booking.timezone }).format(start);
            row.insertCell().textContent = booking.assigned_user || booking.resource || '—'; row.insertCell().textContent = String(booking.guest_count); row.insertCell().textContent = booking.source; row.insertCell().textContent = booking.status.replace('_', ' ');
            const action = row.insertCell(); if (booking.status === 'booked' && can('appointments.write')) {
                for (const [status, label] of [['completed','Complete'],['no_show','No-show'],['cancelled','Cancel']]) { const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = label; button.addEventListener('click', async () => { if (!window.confirm(`${label} this appointment?`)) return; button.disabled = true; try { await api(`/api/v1/appointments/bookings/${booking.id}/status`, { method: 'POST', body: JSON.stringify({ status, reason: '' }) }); await loadAppointmentBookings(); } catch (error) { button.disabled = false; showMessage($('#appointments-message'), error.message, true); } }); action.append(button); }
            }
        }
        if (!state.appointments.bookings.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 8; cell.className = 'empty-cell'; cell.textContent = state.appointments.contactFilter ? 'No bookings are linked to this Contact.' : 'No bookings match this view.'; }
        const loaded = state.appointments.bookings.length; const total = state.appointments.bookingTotal;
        $('#appointment-booking-total').textContent = total === loaded ? `${total} booking${total === 1 ? '' : 's'}` : `Showing ${loaded} of ${total} bookings`;
        const more = $('#appointment-booking-more'); more.hidden = loaded >= total; more.textContent = `Load more bookings (${Math.max(0,total-loaded)} remaining)`;
        more.onclick = async () => { more.disabled = true; more.textContent = 'Loading…'; try { await loadAppointmentBookings({ append: true }); } catch (error) { showMessage($('#appointments-message'), error.message, true); more.disabled = false; more.textContent = 'Retry loading more bookings'; } };
    }
    async function loadAppointmentTypes() {
        const result = await api(`/api/v1/appointments/types?company_id=${appointmentCompany()}`); state.appointments.types = result.types || []; const body = $('#appointment-type-rows'); body.replaceChildren();
        for (const type of state.appointments.types) { const row = body.insertRow(); row.insertCell().textContent = type.name; row.insertCell().textContent = `${type.duration_minutes} min`; row.insertCell().textContent = type.assignment_target === 'resource' ? `${type.assignment_method}; ${type.resource_count ?? 'Resources'}` : `${type.assignment_method}; ${type.user_count ?? 'Users'}`; row.insertCell().textContent = String(type.upcoming_count || 0); const linkCell = row.insertCell(); if (type.published) { const link = document.createElement('a'); link.href = appUrl(type.public_url); link.target = '_blank'; link.rel = 'noopener'; link.textContent = new URL(appUrl(type.public_url), location.origin).href; linkCell.append(link); } else linkCell.textContent = 'Not published'; row.insertCell().textContent = type.published ? 'Published' : 'Unpublished'; const action = row.insertCell(); if (can('appointments.write')) { const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openAppointmentType(Number(type.id))); action.append(edit); } }
        if (!state.appointments.types.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 7; cell.className = 'empty-cell'; cell.textContent = 'No appointment types configured yet.'; }
        $('#appointment-type-total').textContent = `${state.appointments.types.length} appointment type${state.appointments.types.length === 1 ? '' : 's'}`;
    }
    async function loadAppointmentResources() {
        const result = await api(`/api/v1/appointments/resources?company_id=${appointmentCompany()}`); state.appointments.resources = result.resources || []; const body = $('#appointment-resource-rows'); body.replaceChildren();
        for (const resource of state.appointments.resources) { const row = body.insertRow(); row.insertCell().textContent = resource.name; row.insertCell().textContent = String(resource.capacity); row.insertCell().textContent = resource.timezone; row.insertCell().textContent = resource.description; row.insertCell().textContent = Number(resource.active) ? 'Active' : 'Archived'; const action = row.insertCell(); if (can('appointments.write')) { const edit = document.createElement('button'); edit.className = 'button button-small'; edit.type = 'button'; edit.textContent = 'Edit'; edit.addEventListener('click', () => openAppointmentResource(resource)); action.append(edit); } }
        if (!state.appointments.resources.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 6; cell.className = 'empty-cell'; cell.textContent = 'No appointment resources configured yet.'; }
        $('#appointment-resource-total').textContent = `${state.appointments.resources.length} resource${state.appointments.resources.length === 1 ? '' : 's'}`;
    }
    function addAppointmentAvailability(row = {}) {
        const item = document.createElement('div'); item.className = 'appointment-repeat-row appointment-availability-row';
        const day = document.createElement('select'); day.dataset.field = 'weekday'; day.setAttribute('aria-label', 'Weekday'); for (const [index, name] of ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].entries()) day.add(new Option(name, String(index))); day.value = String(row.weekday ?? 0);
        const start = document.createElement('input'); start.type = 'time'; start.dataset.field = 'start_time'; start.value = String(row.start_time || '09:00').slice(0,5); start.required = true;
        const end = document.createElement('input'); end.type = 'time'; end.dataset.field = 'end_time'; end.value = String(row.end_time || '17:00').slice(0,5); end.required = true;
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-small'; remove.textContent = 'Remove'; remove.addEventListener('click', () => item.remove()); item.append(day, start, end, remove); $('#appointment-availability-rows').append(item);
    }
    function addAppointmentQuestion(row = {}) {
        const item = document.createElement('div'); item.className = 'appointment-repeat-row appointment-question-row'; if (row.id) item.dataset.questionId = String(row.id);
        const question = document.createElement('input'); question.dataset.field = 'question'; question.maxLength = 255; question.placeholder = 'Question'; question.value = row.question || '';
        const type = document.createElement('select'); type.dataset.field = 'answer_type'; for (const value of ['text','email','phone','number']) type.add(new Option(value[0].toUpperCase()+value.slice(1),value)); type.value = row.answer_type || 'text';
        const requiredLabel = document.createElement('label'); requiredLabel.className = 'check-row'; const required = document.createElement('input'); required.type = 'checkbox'; required.dataset.field = 'is_required'; required.checked = Boolean(Number(row.is_required)); requiredLabel.append(required, document.createTextNode('Required'));
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-small'; remove.textContent = 'Remove'; remove.addEventListener('click', () => item.remove()); item.append(question,type,requiredLabel,remove); $('#appointment-question-rows').append(item);
    }
    async function openAppointmentType(id = null) {
        if (!can('appointments.write')) return; const form = $('#appointment-type-form'); form.reset(); form.dataset.id = id ? String(id) : ''; $('#appointment-type-error').textContent = ''; $('#appointment-availability-rows').replaceChildren(); $('#appointment-question-rows').replaceChildren();
        await loadAppointmentOptions(appointmentCompany());
        if (id) { const result = await api(`/api/v1/appointments/types/${id}`); const type = result.type; $('#appointment-type-title').textContent = `Edit ${type.name}`; for (const [key, value] of Object.entries(type)) { const field = form.elements[key]; if (!field) continue; if (field.type === 'checkbox') field.checked = Boolean(Number(value)); else if (!['users','resources','availability','questions'].includes(key)) field.value = value ?? ''; } for (const option of $('#appointment-type-users').options) option.selected = (type.users || []).some((row) => String(row.id) === option.value); for (const option of $('#appointment-type-resources').options) option.selected = (type.resources || []).some((row) => String(row.id) === option.value); for (const row of type.availability || []) addAppointmentAvailability(row); for (const row of type.questions || []) addAppointmentQuestion(row); }
        else { $('#appointment-type-title').textContent = 'New Appointment Type'; $('#appointment-type-company').value = String(appointmentCompany()); addAppointmentAvailability({ weekday: 0 }); }
        toggleAppointmentAssignment(); $('#appointment-type-dialog').showModal();
    }
    function toggleAppointmentAssignment() { const target = $('#appointment-assignment-target').value; $('#appointment-users-wrap').hidden = target !== 'user'; $('#appointment-resources-wrap').hidden = target !== 'resource'; }
    async function saveAppointmentType(event) {
        event.preventDefault(); const form = $('#appointment-type-form'); if (!form.reportValidity()) return; $('#appointment-type-save-state').textContent = 'Saving…'; $('#appointment-type-error').textContent = '';
        const payload = Object.fromEntries(new FormData(form).entries()); payload.duration_minutes = Number(payload.duration_minutes); payload.slot_interval_minutes = Number(payload.slot_interval_minutes); payload.prebooking_minutes = Number(payload.prebooking_minutes); payload.booking_horizon_days = Number(payload.booking_horizon_days); payload.cancellation_cutoff_minutes = Number(payload.cancellation_cutoff_minutes); payload.capacity_per_slot = Number(payload.capacity_per_slot); payload.allow_guests = form.elements.allow_guests.checked; payload.published = form.elements.published.checked;
        payload.user_ids = [...$('#appointment-type-users').selectedOptions].map((o) => Number(o.value)); payload.resource_ids = [...$('#appointment-type-resources').selectedOptions].map((o) => Number(o.value));
        payload.availability = $$('.appointment-availability-row').map((row) => Object.fromEntries([...row.querySelectorAll('[data-field]')].map((field) => [field.dataset.field, field.value])));
        payload.questions = $$('.appointment-question-row').map((row) => { const values = Object.fromEntries([...row.querySelectorAll('[data-field]')].map((field) => [field.dataset.field, field.type === 'checkbox' ? field.checked : field.value])); if (row.dataset.questionId) values.id = Number(row.dataset.questionId); return values; });
        const id = Number(form.dataset.id); try { await api(id ? `/api/v1/appointments/types/${id}` : '/api/v1/appointments/types', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); $('#appointment-type-dialog').close(); await loadAppointmentTypes(); showMessage($('#appointments-message'), 'Appointment type saved.'); } catch (error) { $('#appointment-type-error').textContent = error.message; } finally { $('#appointment-type-save-state').textContent = ''; }
    }
    async function openAppointmentResource(resource = null) {
        if (!can('appointments.write')) return; const form = $('#appointment-resource-form'); form.reset(); form.dataset.id = resource ? String(resource.id) : ''; $('#appointment-resource-error').textContent = ''; await loadAppointmentOptions(appointmentCompany()); $('#appointment-resource-company').value = String(appointmentCompany());
        $('#appointment-resource-title').textContent = resource ? `Edit ${resource.name}` : 'New Resource'; if (resource) for (const [key,value] of Object.entries(resource)) { const field=form.elements[key]; if(field?.type==='checkbox')field.checked=Boolean(Number(value)); else if(field)field.value=value??''; } $('#appointment-resource-dialog').showModal();
    }
    async function saveAppointmentResource(event) {
        event.preventDefault(); const form=$('#appointment-resource-form'); if(!form.reportValidity())return; const payload=Object.fromEntries(new FormData(form).entries());payload.capacity=Number(payload.capacity);payload.active=form.elements.active.checked;const id=Number(form.dataset.id);try{await api(id?`/api/v1/appointments/resources/${id}`:'/api/v1/appointments/resources',{method:id?'PATCH':'POST',body:JSON.stringify(payload)});$('#appointment-resource-dialog').close();await loadAppointmentResources();showMessage($('#appointments-message'),'Resource saved.');}catch(error){$('#appointment-resource-error').textContent=error.message;}
    }
    async function openAppointmentBooking(contact = null) {
        if (!can('appointments.write')) return; const form=$('#appointment-booking-form');form.reset();$('#appointment-booking-error').textContent='';$('#appointment-booking-answers').replaceChildren();await loadAppointmentOptions(appointmentCompany());$('#appointment-booking-company').value=String(contact?.company_id||appointmentCompany());
        const typeResult=await api(`/api/v1/appointments/types?company_id=${$('#appointment-booking-company').value}`);state.appointments.types=typeResult.types||[];state.appointments.currentType=null;state.appointments.slots=[];fillSelect($('#appointment-booking-type'),state.appointments.types,{placeholder:'Select appointment type'});$('#appointment-booking-contact').value=contact?.id?String(contact.id):'';form.elements.name.value=contact?.name||'';form.elements.email.value=contact?.email||'';form.elements.phone.value=contact?.phone||'';$('#appointment-booking-date').value=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);$('#appointment-booking-slot').replaceChildren(new Option('Select a date first',''));$('#appointment-booking-provider-wrap').hidden=true;$('#appointment-booking-provider').required=false;$('#appointment-booking-dialog').showModal();
    }
    async function loadAppointmentBookingSetup() {
        const company=$('#appointment-booking-company').value;await loadAppointmentOptions(company);if(!state.appointments.options.contacts?.some((row)=>String(row.id)===$('#appointment-booking-contact').value))$('#appointment-booking-contact').value='';const result=await api(`/api/v1/appointments/types?company_id=${company}`);state.appointments.types=result.types||[];state.appointments.currentType=null;state.appointments.slots=[];$('#appointment-booking-provider-wrap').hidden=true;$('#appointment-booking-provider').required=false;fillSelect($('#appointment-booking-type'),state.appointments.types,{placeholder:'Select appointment type'});$('#appointment-booking-slot').replaceChildren(new Option('Select appointment type and date',''));$('#appointment-booking-answers').replaceChildren();
    }
    async function loadAppointmentBookingType() {
        const id=Number($('#appointment-booking-type').value);$('#appointment-booking-answers').replaceChildren();$('#appointment-booking-slot').replaceChildren(new Option('Loading available times…',''));if(!id){$('#appointment-booking-provider').required=false;$('#appointment-booking-provider-wrap').hidden=true;return;}const result=await api(`/api/v1/appointments/types/${id}`);state.appointments.currentType=result.type;$('#appointment-booking-provider-wrap').hidden=result.type.assignment_method!=='visitor';$('#appointment-booking-provider').required=result.type.assignment_method==='visitor';
        for(const question of result.type.questions||[]){const label=document.createElement('label');label.textContent=question.question;const input=document.createElement(question.answer_type==='text'?'textarea':'input');input.dataset.questionId=String(question.id);input.type=question.answer_type==='number'?'number':question.answer_type==='email'?'email':question.answer_type==='phone'?'tel':'text';input.required=Boolean(Number(question.is_required));input.maxLength=2000;label.append(input);$('#appointment-booking-answers').append(label);}await loadAppointmentBookingSlots();
    }
    async function loadAppointmentBookingSlots() {
        const id=Number($('#appointment-booking-type').value),date=$('#appointment-booking-date').value,guests=Number($('#appointment-booking-guests').value||1);if(!id||!date)return;const result=await api(`/api/v1/appointments/types/${id}/slots?date=${encodeURIComponent(date)}&guest_count=${guests}`);state.appointments.slots=result.slots||[];const unique=[...new Map(state.appointments.slots.map((row)=>[row.starts_at_utc,row])).values()];fillSelect($('#appointment-booking-slot'),unique,{placeholder:unique.length?'Select available time':'No available times',valueKey:'starts_at_utc',label:(row)=>row.label.replace(/ · .*$/,'')});updateAppointmentSlotProviders();}
    function updateAppointmentSlotProviders() {
        const visitor=state.appointments.currentType?.assignment_method==='visitor';$('#appointment-booking-provider-wrap').hidden=!visitor;$('#appointment-booking-provider').required=visitor;if(!visitor)return;const start=$('#appointment-booking-slot').value;const providers=[...new Map(state.appointments.slots.filter((row)=>row.starts_at_utc===start).map((row)=>[row.provider_key,{key:row.provider_key,name:row.provider_name}])).values()];fillSelect($('#appointment-booking-provider'),providers,{placeholder:'Select provider',valueKey:'key'});
    }
    async function saveAppointmentBooking(event) {
        event.preventDefault();const form=$('#appointment-booking-form');if(!form.reportValidity())return;$('#appointment-booking-error').textContent='';const payload=Object.fromEntries(new FormData(form).entries());payload.guest_count=Number(payload.guest_count);payload.appointment_type_id=Number(payload.appointment_type_id);payload.contact_id=payload.contact_id?Number(payload.contact_id):null;payload.answers=[...$('#appointment-booking-answers').querySelectorAll('[data-question-id]')].map((input)=>({question_id:Number(input.dataset.questionId),answer:input.value}));try{await api('/api/v1/appointments/bookings',{method:'POST',body:JSON.stringify(payload)});$('#appointment-booking-dialog').close();state.appointments.contactFilter=null;state.appointments.bookingFilter=null;await loadAppointmentBookings();showMessage($('#appointments-message'),'Appointment booked and linked to Contacts where selected.');}catch(error){$('#appointment-booking-error').textContent=error.message;}
    }

    async function initializeManufacturing() {
        const select = $('#manufacturing-company');
        fillSelect(select, state.companies, { placeholder: 'Select company' });
        if (!select.value && state.defaultCompanyId) select.value = String(state.defaultCompanyId);
        if (!select.value && state.companies.length === 1) select.value = String(state.companies[0].id);
        await loadManufacturingOptions(select.value);
        await loadManufacturingRecords();
    }
    async function loadManufacturingOptions(companyId) {
        if (!companyId) { state.manufacturing.options = {}; return; }
        const result = await api(`/api/v1/manufacturing/options?company_id=${encodeURIComponent(companyId)}`);
        state.manufacturing.options = result;
        $('#manufacturing-scheduled-label').textContent=`Scheduled Date and Time (${result.company_timezone||'company local time'})`;
        $('#manufacturing-cost-label').textContent=`Cost per Hour (${result.company_currency||'company currency'})`;
        const companies = state.companies;
        for (const id of ['manufacturing-work-center-company','manufacturing-bom-company','manufacturing-order-company']) {
            fillSelect($( `#${id}`), companies, { placeholder: 'Select company', keepValue: String(companyId) });
        }
        fillSelect($('#manufacturing-bom-product'), result.products, { placeholder: 'Select finished product', label: (row) => `${row.name}${row.internal_reference ? ` · ${row.internal_reference}` : ''}` });
        fillSelect($('#manufacturing-bom-uom'), result.uoms, { placeholder: 'Select unit', label: (row) => `${row.name} (${row.abbreviation})` });
        fillSelect($('#manufacturing-order-bom'), result.boms.filter((row) => Number(row.active)), { placeholder: 'Select BoM', label: (row) => `${row.product_name}${row.code ? ` · ${row.code}` : ''}` });
        const sourceLocations = result.locations.filter((row) => ['internal','transit'].includes(row.location_usage));
        const productionLocations = result.locations.filter((row) => row.location_usage === 'production');
        const internalLocations = result.locations.filter((row) => row.location_usage === 'internal');
        fillSelect($('#manufacturing-order-source'), sourceLocations, { placeholder: 'Select component source', label: (row) => row.complete_name });
        fillSelect($('#manufacturing-order-production'), productionLocations, { placeholder: 'Select Production location', label: (row) => row.complete_name });
        fillSelect($('#manufacturing-order-destination'), internalLocations, { placeholder: 'Select finished goods location', label: (row) => row.complete_name });
        const defaultSource = sourceLocations.find((row) => row.location_usage === 'internal');
        const defaultProduction = productionLocations[0];
        const defaultDestination = internalLocations.find((row) => row.id !== defaultSource?.id) || internalLocations[0];
        if (!$('#manufacturing-order-source').value && defaultSource) $('#manufacturing-order-source').value = String(defaultSource.id);
        if (!$('#manufacturing-order-production').value && defaultProduction) $('#manufacturing-order-production').value = String(defaultProduction.id);
        if (!$('#manufacturing-order-destination').value && defaultDestination) $('#manufacturing-order-destination').value = String(defaultDestination.id);
    }
    async function loadManufacturingRecords() {
        const companyId = $('#manufacturing-company').value;
        if (!companyId) { renderManufacturingRecords([]); return; }
        const view = state.manufacturing.view;
        let result;
        if (view === 'work-centers') result = await api(`/api/v1/manufacturing/work-centers?company_id=${encodeURIComponent(companyId)}`);
        else if (view === 'boms') result = await api(`/api/v1/manufacturing/boms?company_id=${encodeURIComponent(companyId)}`);
        else {
            const query = new URLSearchParams({ company_id: companyId, state: $('#manufacturing-state').value, q: $('#manufacturing-search').value.trim() });
            result = await api(`/api/v1/manufacturing/orders?${query}`);
        }
        state.manufacturing.records = result.records || [];
        renderManufacturingRecords(state.manufacturing.records);
    }
    function updateManufacturingActions() {
        const actions = [];
        const add = document.createElement('button'); add.className = 'button button-primary'; add.type = 'button';
        if (state.manufacturing.view === 'work-centers') { add.textContent = 'New Work Center'; add.disabled = !can('manufacturing.write'); add.addEventListener('click', () => openManufacturingWorkCenter()); }
        else if (state.manufacturing.view === 'boms') { add.textContent = 'New Bill of Materials'; add.disabled = !can('manufacturing.write'); add.addEventListener('click', () => openManufacturingBom()); }
        else { add.textContent = 'New Manufacturing Order'; add.disabled = !can('manufacturing.write'); add.addEventListener('click', () => openManufacturingOrder()); }
        actions.push(add); $('#page-actions').replaceChildren(...actions);
    }
    function setManufacturingView(view) {
        state.manufacturing.view = view;
        $$('.manufacturing-view-button').forEach((button) => button.classList.toggle('active', button.dataset.manufacturingView === view));
        $('#manufacturing-state').hidden = view !== 'orders'; $('#manufacturing-search').hidden = view === 'work-centers';
        updateManufacturingActions(); loadManufacturingRecords().catch((error) => showMessage($('#manufacturing-message'), error.message, true));
    }
    function renderManufacturingRecords(rows) {
        const view = state.manufacturing.view;
        const head = $('#manufacturing-head'), body = $('#manufacturing-rows'); body.replaceChildren();
        if (view === 'work-centers') {
            head.innerHTML = '<tr><th>Code</th><th>Work Center</th><th>Capacity</th><th>Efficiency</th><th>Cost / Hour</th><th>Status</th><th></th></tr>';
            for (const row of rows) { const tr=document.createElement('tr'); tr.innerHTML=`<td>${escapeHtml(row.code)}</td><td>${escapeHtml(row.name)}</td><td>${escapeHtml(row.capacity)}</td><td>${escapeHtml(row.time_efficiency)}%</td><td>${escapeHtml(row.cost_per_hour)}</td><td>${Number(row.active)?'Active':'Archived'}</td><td><button class="button button-small" data-mrp-action="edit-center" data-id="${Number(row.id)}" ${can('manufacturing.write')?'':'disabled'}>Open</button></td>`;body.append(tr); }
        } else if (view === 'boms') {
            head.innerHTML = '<tr><th>Finished Product</th><th>Reference</th><th>Output</th><th>Components</th><th>Operations</th><th>Status</th><th></th></tr>';
            for (const row of rows) { const tr=document.createElement('tr'); tr.innerHTML=`<td>${escapeHtml(row.product_name)}</td><td>${escapeHtml(row.code||'—')}</td><td>${escapeHtml(row.product_quantity)} ${escapeHtml(row.uom)}</td><td>${Number(row.component_count)}</td><td>${Number(row.operation_count)}</td><td>${Number(row.active)?'Active':'Archived'}</td><td><button class="button button-small" data-mrp-action="edit-bom" data-id="${Number(row.id)}" ${can('manufacturing.write')?'':'disabled'}>Open</button></td>`;body.append(tr); }
        } else {
            head.innerHTML = '<tr><th>Reference</th><th>Finished Product</th><th>Planned</th><th>Produced</th><th>Scheduled</th><th>Origin</th><th>Status</th><th>Actual Cost</th><th></th></tr>';
            for (const row of rows) { const tr=document.createElement('tr'); tr.innerHTML=`<td>${escapeHtml(row.reference)}</td><td>${escapeHtml(row.product_name)}</td><td>${escapeHtml(row.planned_quantity)} ${escapeHtml(row.uom)}</td><td>${escapeHtml(row.produced_quantity)} ${escapeHtml(row.uom)}</td><td>${escapeHtml(row.scheduled_at||'—')}</td><td>${escapeHtml(row.origin||'—')}</td><td>${escapeHtml(row.state.replace('_',' '))}</td><td>${escapeHtml(row.total_cost||'0.000000')}</td><td><button class="button button-small" data-mrp-action="open-order" data-id="${Number(row.id)}">Open</button></td>`;body.append(tr); }
        }
        if (!rows.length) { const tr=document.createElement('tr'); const td=document.createElement('td'); td.colSpan=view==='work-centers'?7:view==='boms'?7:9;td.className='empty-cell';td.textContent=view==='work-centers'?'No work centers configured.':view==='boms'?'No Bills of Materials configured.':'No Manufacturing Orders found.';tr.append(td);body.append(tr); }
        $('#manufacturing-total').textContent=`${rows.length} ${view==='work-centers'?'work centers':view==='boms'?'Bills of Materials':'Manufacturing Orders'}`;
    }
    function openManufacturingWorkCenter(record = null) {
        const form=$('#manufacturing-work-center-form');form.reset();state.manufacturing.currentWorkCenter=record;form.dataset.id=record?String(record.id):'';$('#manufacturing-work-center-title').textContent=record?'Work Center':'New Work Center';
        fillSelect($('#manufacturing-work-center-company'),state.companies,{placeholder:'Select company',keepValue:String($('#manufacturing-company').value||state.defaultCompanyId||'')});
        if(record){form.elements.company_id.value=String($('#manufacturing-company').value);for(const key of ['code','name','capacity','time_efficiency','cost_per_hour'])form.elements[key].value=record[key]??'';form.elements.active.checked=Boolean(Number(record.active));}
        showMessage($('#manufacturing-work-center-error'));$('#manufacturing-work-center-dialog').showModal();
    }
    function addManufacturingComponentRow(value = {}) {
        const row=document.createElement('div');row.className='mrp-edit-row';
        const product=document.createElement('select');product.setAttribute('aria-label','Component product');product.dataset.mrpProduct='1';fillSelect(product,state.manufacturing.options.products,{placeholder:'Select component'});
        const quantity=document.createElement('input');quantity.type='number';quantity.min='0.000001';quantity.step='0.000001';quantity.required=true;quantity.placeholder='Quantity';quantity.setAttribute('aria-label','Component quantity');quantity.dataset.mrpQuantity='1';
        const uom=document.createElement('select');uom.required=true;uom.setAttribute('aria-label','Component unit');uom.dataset.mrpUom='1';fillSelect(uom,state.manufacturing.options.uoms,{placeholder:'Unit',label:(unit)=>`${unit.abbreviation}`});
        const scrap=document.createElement('input');scrap.type='number';scrap.min='0';scrap.max='100';scrap.step='0.0001';scrap.value='0';scrap.placeholder='Scrap %';scrap.setAttribute('aria-label','Scrap percentage');scrap.dataset.mrpScrap='1';
        const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>row.remove());
        product.addEventListener('change',()=>{const item=state.manufacturing.options.products.find((candidate)=>Number(candidate.id)===Number(product.value));if(item)uom.value=String(item.uom_id);});
        row.append(product,quantity,uom,scrap,remove);$('#manufacturing-bom-components').append(row);
        if(value.product_id){product.value=String(value.product_id);quantity.value=value.quantity??'';uom.value=String(value.uom_id||'');scrap.value=value.scrap_percent??'0';}
    }
    function addManufacturingOperationRow(value = {}) {
        const row=document.createElement('div');row.className='mrp-edit-row mrp-operation-row';
        const name=document.createElement('input');name.maxLength=120;name.required=true;name.placeholder='Operation';name.setAttribute('aria-label','Operation name');name.dataset.mrpOperationName='1';
        const center=document.createElement('select');center.required=true;center.setAttribute('aria-label','Work center');center.dataset.mrpCenter='1';fillSelect(center,state.manufacturing.options.work_centers.filter((item)=>Number(item.active)),{placeholder:'Work center',label:(item)=>`${item.code} · ${item.name}`});
        const duration=document.createElement('input');duration.type='number';duration.min='0';duration.step='0.001';duration.value='0';duration.placeholder='Expected minutes';duration.setAttribute('aria-label','Expected duration minutes');duration.dataset.mrpDuration='1';
        const worksheet=document.createElement('input');worksheet.maxLength=4000;worksheet.placeholder='Instructions (optional)';worksheet.setAttribute('aria-label','Operation worksheet');worksheet.dataset.mrpWorksheet='1';
        const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>row.remove());
        row.append(name,center,duration,worksheet,remove);$('#manufacturing-bom-operations').append(row);
        if(value.name){name.value=value.name;center.value=String(value.work_center_id);duration.value=value.expected_duration_minutes??0;worksheet.value=value.worksheet??'';}
    }
    async function openManufacturingBom(id = null) {
        const form=$('#manufacturing-bom-form');form.reset();state.manufacturing.currentBom=null;form.dataset.id=id?String(id):'';$('#manufacturing-bom-title').textContent=id?'Bill of Materials':'New Bill of Materials';
        fillSelect($('#manufacturing-bom-company'),state.companies,{placeholder:'Select company',keepValue:String($('#manufacturing-company').value||state.defaultCompanyId||'')});$('#manufacturing-bom-components').replaceChildren();$('#manufacturing-bom-operations').replaceChildren();
        if(id){const result=await api(`/api/v1/manufacturing/boms/${id}`);const bom=result.bom;state.manufacturing.currentBom=bom;form.elements.company_id.value=String(bom.company_id);form.elements.product_id.value=String(bom.product_id);form.elements.code.value=bom.code||'';form.elements.bom_type.value=bom.bom_type||'manufacture';form.elements.product_quantity.value=bom.product_quantity;form.elements.uom_id.value=String(bom.uom_id);form.elements.active.value=String(Number(bom.active));for(const row of bom.components)addManufacturingComponentRow(row);for(const row of bom.operations)addManufacturingOperationRow(row);}
        else{form.elements.company_id.value=String($('#manufacturing-company').value||state.defaultCompanyId||'');form.elements.active.value='1';}
        showMessage($('#manufacturing-bom-error'));$('#manufacturing-bom-dialog').showModal();
    }
    function openManufacturingOrder() {
        const form=$('#manufacturing-order-form');form.reset();form.elements.company_id.value=String($('#manufacturing-company').value||state.defaultCompanyId||'');form.elements.planned_quantity.value='1';
        showMessage($('#manufacturing-order-error'));$('#manufacturing-order-dialog').showModal();
    }
    async function saveManufacturingWorkCenter(event) {
        event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;const id=form.dataset.id;const data=Object.fromEntries(new FormData(form).entries());data.active=form.elements.active.checked;try{await api(id?`/api/v1/manufacturing/work-centers/${id}`:'/api/v1/manufacturing/work-centers',{method:id?'PATCH':'POST',body:JSON.stringify(data)});$('#manufacturing-work-center-dialog').close();await initializeManufacturing();showMessage($('#manufacturing-message'),'Work center saved.');}catch(error){showMessage($('#manufacturing-work-center-error'),error.message,true);}
    }
    async function saveManufacturingBom(event) {
        event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;const id=form.dataset.id;const data=Object.fromEntries(new FormData(form).entries());data.company_id=Number(data.company_id);data.product_id=Number(data.product_id);data.uom_id=Number(data.uom_id);data.product_quantity=String(data.product_quantity);data.active=data.active==='1';data.components=$$('.mrp-edit-row:not(.mrp-operation-row)',$('#manufacturing-bom-components')).map((row,index)=>({sequence_no:(index+1)*10,product_id:Number(row.querySelector('[data-mrp-product]').value),quantity:String(row.querySelector('[data-mrp-quantity]').value),uom_id:Number(row.querySelector('[data-mrp-uom]').value),scrap_percent:String(row.querySelector('[data-mrp-scrap]').value||0)}));data.operations=$$('.mrp-operation-row',$('#manufacturing-bom-operations')).map((row,index)=>({sequence_no:(index+1)*10,name:row.querySelector('[data-mrp-operation-name]').value,work_center_id:Number(row.querySelector('[data-mrp-center]').value),expected_duration_minutes:String(row.querySelector('[data-mrp-duration]').value||0),worksheet:row.querySelector('[data-mrp-worksheet]').value}));try{await api(id?`/api/v1/manufacturing/boms/${id}`:'/api/v1/manufacturing/boms',{method:id?'PATCH':'POST',body:JSON.stringify(data)});$('#manufacturing-bom-dialog').close();await initializeManufacturing();showMessage($('#manufacturing-message'),'Bill of Materials saved.');}catch(error){showMessage($('#manufacturing-bom-error'),error.message,true);}
    }
    async function saveManufacturingOrder(event) {
        event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;const data=Object.fromEntries(new FormData(form).entries());data.company_id=Number(data.company_id);data.bom_id=Number(data.bom_id);data.planned_quantity=String(data.planned_quantity);for(const key of ['source_location_id','production_location_id','destination_location_id'])data[key]=Number(data[key]);try{await api('/api/v1/manufacturing/orders',{method:'POST',body:JSON.stringify(data)});$('#manufacturing-order-dialog').close();await loadManufacturingRecords();showMessage($('#manufacturing-message'),'Draft Manufacturing Order created.');}catch(error){showMessage($('#manufacturing-order-error'),error.message,true);}
    }
    function addManufacturingLotLine(container,productId,sourceLocationId,initial={}) {
        const row=document.createElement('div');row.className='mrp-lot-line';const lot=document.createElement('select');lot.required=true;lot.setAttribute('aria-label','Available lot or serial');const rows=(state.manufacturing.options.available_lots||[]).filter((item)=>Number(item.product_id)===Number(productId)&&Number(item.location_id)===Number(sourceLocationId));fillSelect(lot,rows,{placeholder:'Select available lot / serial',valueKey:'lot_id',label:(item)=>`${item.lot_code} · available ${item.available_quantity}${item.expiration_date?` · exp ${item.expiration_date}`:''}`});
        const quantity=document.createElement('input');quantity.type='number';quantity.min='0.000001';quantity.step='0.000001';quantity.required=true;quantity.placeholder='Quantity';quantity.setAttribute('aria-label','Lot quantity');quantity.value=initial.quantity??'';
        const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>row.remove());row.append(lot,quantity,remove);container.append(row);if(initial.lot_id)lot.value=String(initial.lot_id);return row;
    }
    function addManufacturingOutputLine(initial={}) {
        const row=document.createElement('div');row.className='mrp-output-line';const code=document.createElement('input');code.maxLength=120;code.required=true;code.placeholder='New or existing lot / serial';code.setAttribute('aria-label','Finished lot or serial');code.value=initial.lot_code||'';
        const date=document.createElement('input');date.type='date';date.setAttribute('aria-label','Expiration date');date.value=initial.expiration_date||'';
        const quantity=document.createElement('input');quantity.type='number';quantity.min='0.000001';quantity.step='0.000001';quantity.required=true;quantity.placeholder='Quantity';quantity.setAttribute('aria-label','Finished quantity');quantity.value=initial.quantity||'';
        const remove=document.createElement('button');remove.type='button';remove.className='button button-small';remove.textContent='Remove';remove.addEventListener('click',()=>row.remove());row.append(code,date,quantity,remove);$('#manufacturing-output-lots').append(row);
    }
    function syncManufacturingOutputQuantity() {
        const order=state.manufacturing.currentOrder;if(!order)return;const quantityInput=$('#manufacturing-produced-quantity');const target=Number(quantityInput.value);const section=$('#manufacturing-output-lots');const lines=$$('.mrp-output-line',section);const tracking=state.manufacturing.options.products.find((product)=>Number(product.id)===Number(order.product_id))?.tracking||'none';
        if(tracking==='serial'){
            if(!Number.isInteger(target)||target<1||target>500){quantityInput.setCustomValidity('Serial-tracked finished quantity must be a whole number from 1 to 500.');return;}
            quantityInput.setCustomValidity('');while(lines.length>target){lines.at(-1).remove();lines.pop();}while(lines.length<target){addManufacturingOutputLine({quantity:'1'});lines.push(section.lastElementChild);}
        }else{quantityInput.setCustomValidity('');if(lines.length===1)lines[0].children[2].value=quantityInput.value;}
        const help=$('#manufacturing-produced-quantity-help');help.textContent=target>0&&target<Number(order.planned_quantity)?`The remaining ${Number(order.planned_quantity)-target} ${order.uom} will become a linked backorder; component quantities are scaled to the produced quantity.`:'Producing less than the remaining quantity creates a linked Manufacturing backorder.';
        const produce=$('#manufacturing-order-produce');produce.textContent=target>0&&target<Number(order.planned_quantity)?'Produce & Create Backorder':'Produce Finished Goods';
    }
    async function openManufacturingOrderDetail(id) {
        try{const result=await api(`/api/v1/manufacturing/orders/${id}`);const order=result.order;state.manufacturing.currentOrder=order;$('#manufacturing-detail-title').textContent=`${order.reference} · ${order.product_name}`;const relatedOrders=[{id:Number(order.id),reference:order.reference,state:order.state},...(order.backorders||[])];const backorderMarkup=order.backorder_root_reference?`<p><strong>Backorder chain:</strong> ${escapeHtml(order.backorder_root_reference)}${relatedOrders.length>1?` · ${relatedOrders.map((item)=>`<button type="button" class="link-button" data-mrp-open="${Number(item.id)}">${escapeHtml(item.reference)} · ${escapeHtml(item.state.replace('_',' '))}</button>`).join(' · ')}`:''}</p>`:'';const accountingMoveMarkup=order.accounting_move_id&&can('accounting.read')?`<button id="manufacturing-open-accounting" type="button" class="link-button">${escapeHtml(order.accounting_move_name||'Journal Entry')} · ${escapeHtml(order.accounting_move_state||'—')}</button>`:escapeHtml(order.accounting_move_id?'Restricted':'No Accounting Entry');$('#manufacturing-detail-summary').innerHTML=`<p><strong>Status:</strong> ${escapeHtml(order.state.replace('_',' '))} · <strong>Planned:</strong> ${escapeHtml(order.planned_quantity)} ${escapeHtml(order.uom)} · <strong>Produced:</strong> ${escapeHtml(order.produced_quantity)} ${escapeHtml(order.uom)}</p><p><strong>BoM:</strong> ${escapeHtml(order.bom_code||order.bom_id)} · <strong>Components:</strong> ${escapeHtml(order.source_location)} → Production · <strong>Finished:</strong> Production → ${escapeHtml(order.destination_location)}</p><p><strong>Scheduled:</strong> ${escapeHtml(order.scheduled_at||'—')} · <strong>Origin:</strong> ${escapeHtml(order.origin||'—')}</p>${backorderMarkup}<p><strong>Accounting Entry:</strong> ${accountingMoveMarkup}</p>`;
            const openAccounting=$('#manufacturing-open-accounting');if(openAccounting)openAccounting.addEventListener('click',()=>openAccountingMove(Number(order.accounting_move_id)));
            const componentWrap=$('#manufacturing-detail-components');componentWrap.replaceChildren();const table=document.createElement('table');table.innerHTML='<thead><tr><th>Component</th><th>Required</th><th>Consumed</th><th>Reservation</th><th>Lot Allocation (tracked only)</th></tr></thead>';const tbody=document.createElement('tbody');for(const component of order.components){const tr=document.createElement('tr');const tracked=component.tracking!=='none';let allocation='—';if(tracked&&order.state==='draft'){const wrapper=document.createElement('div');wrapper.className='mrp-component-lot-group';wrapper.dataset.componentId=String(component.bom_component_id);wrapper.dataset.productId=String(component.product_id);const rows=document.createElement('div');rows.className='mrp-component-lot-rows';rows.dataset.rowsFor=String(component.bom_component_id);const required=Number(component.required_quantity);if(component.tracking==='serial'&&Number.isInteger(required)&&required>0&&required<=500){for(let index=0;index<required;index++)addManufacturingLotLine(rows,component.product_id,order.source_location_id,{quantity:'1'});}else{addManufacturingLotLine(rows,component.product_id,order.source_location_id,{quantity:component.tracking==='lot'?component.required_quantity:''});}const add=document.createElement('button');add.type='button';add.className='button button-small';add.textContent='Add Lot Line';add.addEventListener('click',()=>addManufacturingLotLine(rows,component.product_id,order.source_location_id));wrapper.append(rows,add);if(component.tracking==='serial'){const hint=document.createElement('p');hint.className='help-text';hint.textContent='Use a different serial number on each one-unit line.';wrapper.append(hint);}allocation=wrapper;}
                const values=[component.product_name,`${component.required_quantity} ${component.uom}`,`${component.consumed_quantity} ${component.uom}`,component.stock_move_reference?`${component.stock_move_reference} · ${component.stock_move_state}`:'Not reserved',allocation];for(const value of values){const td=document.createElement('td');if(value instanceof Node)td.append(value);else td.textContent=value;tr.append(td);}tbody.append(tr);}table.append(tbody);componentWrap.append(table);
            const workWrap=$('#manufacturing-detail-work-orders');workWrap.replaceChildren();const workTable=document.createElement('table');workTable.innerHTML='<thead><tr><th>Sequence</th><th>Operation</th><th>Work Center</th><th>Expected min</th><th>Actual min</th><th>Status</th><th></th></tr></thead>';const workBody=document.createElement('tbody');for(const work of order.work_orders){const tr=document.createElement('tr');tr.innerHTML=`<td>${Number(work.sequence_no)}</td><td>${escapeHtml(work.name)}</td><td>${escapeHtml(work.work_center_name)}</td><td>${escapeHtml(work.expected_duration_minutes)}</td><td>${escapeHtml(work.actual_duration_minutes)}</td><td>${escapeHtml(work.state.replace('_',' '))}</td><td>${work.state==='pending'&&can('manufacturing.produce')?`<button class="button button-small" data-mrp-work="start" data-id="${Number(work.id)}">Start</button>`:work.state==='in_progress'&&can('manufacturing.produce')?`<button class="button button-small" data-mrp-work="done" data-id="${Number(work.id)}">Mark Done</button>`:''}</td>`;workBody.append(tr);}if(!order.work_orders.length){const tr=document.createElement('tr');tr.innerHTML='<td class="empty-cell" colspan="7">No operations configured; this order uses one-step production.</td>';workBody.append(tr);}workTable.append(workBody);workWrap.append(workTable);
            const canProduce=['confirmed','in_progress'].includes(order.state)&&can('manufacturing.produce')&&order.work_orders.every((work)=>work.state==='done');const quantityWrap=$('#manufacturing-produced-quantity-wrap');quantityWrap.hidden=!canProduce;const quantityInput=$('#manufacturing-produced-quantity');quantityInput.max=String(order.planned_quantity);quantityInput.value=String(order.planned_quantity);quantityInput.disabled=!canProduce;const finishedTracking=state.manufacturing.options.products.find((product)=>Number(product.id)===Number(order.product_id))?.tracking||'none';const trackedProduct=finishedTracking!=='none';$('#manufacturing-output-lots-section').hidden=!trackedProduct||!canProduce;$('#manufacturing-output-lots').replaceChildren();$('#manufacturing-output-lots-help').textContent=finishedTracking==='serial'?'Enter one unique serial per one-unit line. Serial production quantity must be a whole number; up to 500 lines are accepted per production.':'Enter the finished lot and its quantity; add more lines to split one production across lots.';if(trackedProduct&&canProduce){const planned=Number(order.planned_quantity);if(finishedTracking==='serial'&&Number.isInteger(planned)&&planned>0&&planned<=500){for(let index=0;index<planned;index++)addManufacturingOutputLine({quantity:'1'});}else{addManufacturingOutputLine({quantity:finishedTracking==='lot'?order.planned_quantity:'1'});}}syncManufacturingOutputQuantity();
            $('#manufacturing-detail-cost').textContent=order.state==='done'?`Costs (${order.company_currency}): Material ${order.material_cost} · Operations ${order.operation_cost} · Actual ${order.total_cost} · Inventory valuation ${order.valuation_cost} · Standard-cost variance ${order.cost_variance}`:'';
            $('#manufacturing-order-confirm').hidden=order.state!=='draft'||!can('manufacturing.confirm');$('#manufacturing-order-produce').hidden=!['confirmed','in_progress'].includes(order.state)||!can('manufacturing.produce')||order.work_orders.some((work)=>work.state!=='done');$('#manufacturing-order-cancel').hidden=!['draft','confirmed'].includes(order.state)||!can('manufacturing.write');$('#manufacturing-order-confirm').disabled=!can('inventory.write');$('#manufacturing-order-produce').disabled=!can('inventory.validate');$('#manufacturing-order-cancel').disabled=!can('inventory.write');showMessage($('#manufacturing-detail-error'));$('#manufacturing-detail-dialog').showModal();
        }catch(error){showMessage($('#manufacturing-message'),error.message,true);}
    }
    async function confirmManufacturingOrder() {
        const order=state.manufacturing.currentOrder;if(!order)return;const component_allocations=[];for(const component of order.components){if(component.tracking==='none')continue;const group=$(`.mrp-component-lot-group[data-component-id="${component.bom_component_id}"]`);if(!group)continue;const lines=$$('.mrp-lot-line',group).map((row)=>({lot_id:Number(row.querySelector('select').value),quantity:String(row.querySelector('input').value)})).filter((row)=>row.lot_id&&row.quantity);component_allocations.push({bom_component_id:Number(component.bom_component_id),lines});}
        try{await api(`/api/v1/manufacturing/orders/${order.id}/confirm`,{method:'POST',body:JSON.stringify({component_allocations})});await openManufacturingOrderDetail(order.id);await loadManufacturingRecords();showMessage($('#manufacturing-message'),'Order confirmed; Inventory components are reserved.');}catch(error){showMessage($('#manufacturing-detail-error'),error.message,true);}
    }
    async function produceManufacturingOrder() {
        const order=state.manufacturing.currentOrder;if(!order)return;const quantityInput=$('#manufacturing-produced-quantity');if(!quantityInput.reportValidity())return;const produced_quantity=String(quantityInput.value);const output_lots=$$('.mrp-output-line',$('#manufacturing-output-lots')).map((row)=>({lot_code:row.children[0].value,expiration_date:row.children[1].value||null,quantity:String(row.children[2].value)})).filter((row)=>row.lot_code&&row.quantity);
        try{const result=await api(`/api/v1/manufacturing/orders/${order.id}/produce`,{method:'POST',body:JSON.stringify({produced_quantity,output_lots})});await openManufacturingOrderDetail(order.id);await loadManufacturingRecords();showMessage($('#manufacturing-message'),result.record.backorder?`${produced_quantity} produced; ${result.record.backorder.planned_quantity} remains in ${result.record.backorder.reference}.`:'Components consumed and finished goods received into Inventory.');}catch(error){showMessage($('#manufacturing-detail-error'),error.message,true);}
    }
    async function bindManufacturingEvents() {
        $('#manufacturing-company').addEventListener('change',async()=>{try{await loadManufacturingOptions($('#manufacturing-company').value);await loadManufacturingRecords();}catch(error){showMessage($('#manufacturing-message'),error.message,true);}});
        $('#manufacturing-refresh').addEventListener('click',()=>loadManufacturingRecords().catch((error)=>showMessage($('#manufacturing-message'),error.message,true)));
        $('#manufacturing-state').addEventListener('change',()=>loadManufacturingRecords().catch((error)=>showMessage($('#manufacturing-message'),error.message,true)));
        $('#manufacturing-search').addEventListener('input',()=>{window.clearTimeout(bindEvents.manufacturingSearchTimer);bindEvents.manufacturingSearchTimer=window.setTimeout(()=>loadManufacturingRecords().catch((error)=>showMessage($('#manufacturing-message'),error.message,true)),250);});
        $$('.manufacturing-view-button').forEach((button)=>button.addEventListener('click',()=>setManufacturingView(button.dataset.manufacturingView)));
        $('#manufacturing-rows').addEventListener('click',(event)=>{const button=event.target.closest('[data-mrp-action]');if(!button)return;const row=state.manufacturing.records.find((item)=>Number(item.id)===Number(button.dataset.id));if(button.dataset.mrpAction==='edit-center'&&row)openManufacturingWorkCenter(row);else if(button.dataset.mrpAction==='edit-bom'&&row)openManufacturingBom(Number(row.id));else if(button.dataset.mrpAction==='open-order')openManufacturingOrderDetail(Number(button.dataset.id));});
        $('#manufacturing-work-center-form').addEventListener('submit',saveManufacturingWorkCenter);$('#manufacturing-bom-form').addEventListener('submit',saveManufacturingBom);$('#manufacturing-order-form').addEventListener('submit',saveManufacturingOrder);
        const switchManufacturingCompany=async(select,errorTarget)=>{try{$('#manufacturing-company').value=select.value;await loadManufacturingOptions(select.value);await loadManufacturingRecords();}catch(error){showMessage(errorTarget,error.message,true);}};
        $('#manufacturing-work-center-company').addEventListener('change',()=>switchManufacturingCompany($('#manufacturing-work-center-company'),$('#manufacturing-work-center-error')));
        $('#manufacturing-bom-company').addEventListener('change',async()=>{await switchManufacturingCompany($('#manufacturing-bom-company'),$('#manufacturing-bom-error'));$('#manufacturing-bom-product').value='';$('#manufacturing-bom-uom').value='';$('#manufacturing-bom-components').replaceChildren();$('#manufacturing-bom-operations').replaceChildren();});
        $('#manufacturing-bom-product').addEventListener('change',()=>{const product=state.manufacturing.options.products?.find((row)=>Number(row.id)===Number($('#manufacturing-bom-product').value));if(product)$('#manufacturing-bom-uom').value=String(product.uom_id);});
        $('#manufacturing-add-component').addEventListener('click',()=>addManufacturingComponentRow());$('#manufacturing-add-operation').addEventListener('click',()=>addManufacturingOperationRow());
        $('#manufacturing-order-company').addEventListener('change',()=>switchManufacturingCompany($('#manufacturing-order-company'),$('#manufacturing-order-error')));
        $('#manufacturing-order-confirm').addEventListener('click',confirmManufacturingOrder);$('#manufacturing-order-produce').addEventListener('click',produceManufacturingOrder);
        $('#manufacturing-order-cancel').addEventListener('click',async()=>{const order=state.manufacturing.currentOrder;if(!order)return;try{await api(`/api/v1/manufacturing/orders/${order.id}/cancel`,{method:'POST',body:JSON.stringify({})});await openManufacturingOrderDetail(order.id);await loadManufacturingRecords();}catch(error){showMessage($('#manufacturing-detail-error'),error.message,true);}});
        $('#manufacturing-detail-work-orders').addEventListener('click',async(event)=>{const button=event.target.closest('[data-mrp-work]');if(!button)return;const path=button.dataset.mrpWork==='start'?'start':'done';try{await api(`/api/v1/manufacturing/work-orders/${button.dataset.id}/${path}`,{method:'POST',body:JSON.stringify({})});await openManufacturingOrderDetail(state.manufacturing.currentOrder.id);await loadManufacturingRecords();}catch(error){showMessage($('#manufacturing-detail-error'),error.message,true);}});$('#manufacturing-detail-summary').addEventListener('click',(event)=>{const button=event.target.closest('[data-mrp-open]');if(button)openManufacturingOrderDetail(Number(button.dataset.mrpOpen));});$('#manufacturing-produced-quantity').addEventListener('input',syncManufacturingOutputQuantity);
        $('#manufacturing-add-output-lot').addEventListener('click',()=>addManufacturingOutputLine());
    }

    function bindEvents() {
        initializeProductFlowPanel();
        $('#notification-button').addEventListener('click', openNotificationInbox);
        $('#notification-close').addEventListener('click', () => $('#notification-dialog').close());
        $('#notification-refresh').addEventListener('click', () => loadNotifications().catch((error) => showMessage($('#notification-message'), error.message, true)));
        $('#notification-read-all').addEventListener('click', async (event) => {
            const button = event.currentTarget; button.disabled = true;
            try { await api('/api/v1/notifications/read-all', { method: 'POST', body: JSON.stringify({}) }); await loadNotifications(); }
            catch (error) { showMessage($('#notification-message'), error.message, true); button.disabled = false; }
        });
        $('#notification-load-more').addEventListener('click', async (event) => {
            const button = event.currentTarget; button.disabled = true;
            try { await loadNotifications(true); } catch (error) { showMessage($('#notification-message'), error.message, true); button.disabled = false; }
        });
        $$('.nav-button').forEach((button) => button.addEventListener('click', () => {
            if (button.dataset.page === 'dashboards' && !can('dashboards.read')) return;
            if (button.dataset.page === 'settings' && !can('settings.read')) return;
            if (button.dataset.page === 'contracts' && !can('contracts.read')) return;
            if (button.dataset.page === 'discuss' && !can('discuss.read')) return;
            if (button.dataset.page === 'appointments' && !can('appointments.read')) return;
            if (button.dataset.page === 'calendar' && !(can('calendar.read') || can('calendar.write'))) return;
            if (button.dataset.page === 'crm' && !can('crm.read')) return;
            if (button.dataset.page === 'todo' && !can('todo.read')) return;
            if (button.dataset.page === 'sales' && !can('sales.read')) return;
            if (button.dataset.page === 'invoicing' && !can('invoicing.read')) return;
            if (button.dataset.page === 'accounting' && !can('accounting.read')) return;
            if (button.dataset.page === 'inventory' && !can('inventory.read')) return;
            if (button.dataset.page === 'products' && !can('products.create')) return;
            if (button.dataset.page === 'manufacturing' && !can('manufacturing.read')) return;
            if (button.dataset.page === 'approvals' && !(can('approvals.read')||can('approvals.write')||can('sales.approve')||can('purchase.approve'))) return;
            selectPage(button.dataset.page);
        }));
        $('#contracts-company').addEventListener('change', () => initializeContracts($('#contracts-company').value).catch((error) => showMessage($('#contracts-message'), error.message, true)));
        $('#contracts-type').addEventListener('change', () => initializeContracts().catch((error) => showMessage($('#contracts-message'), error.message, true)));
        $('#contracts-status').addEventListener('change', () => initializeContracts().catch((error) => showMessage($('#contracts-message'), error.message, true)));
        $('#contracts-refresh').addEventListener('click', () => initializeContracts().catch((error) => showMessage($('#contracts-message'), error.message, true)));
        $('#contracts-search').addEventListener('input', () => { window.clearTimeout(bindEvents.contractSearchTimer); bindEvents.contractSearchTimer = window.setTimeout(() => initializeContracts().catch((error) => showMessage($('#contracts-message'), error.message, true)), 250); });
        $('#contract-form').addEventListener('submit', saveContract);
        $('#contract-company').addEventListener('change', async () => { try { await loadContractOptions($('#contract-company').value, $('#contract-type').value, ''); } catch (error) { setFormError('contract-form-error', error.message); } });
        $('#contract-type').addEventListener('change', async () => { try { await loadContractOptions($('#contract-company').value, $('#contract-type').value, ''); } catch (error) { setFormError('contract-form-error', error.message); } });
        $('#dashboard-company').addEventListener('change', () => initializeDashboard().catch((error) => showMessage($('#dashboard-message'), error.message, true)));
        $('#dashboard-refresh').addEventListener('click', () => initializeDashboard().catch((error) => showMessage($('#dashboard-message'), error.message, true)));
        $('#discuss-company').addEventListener('change', async () => { state.discussCompanyId = Number($('#discuss-company').value) || null; state.discussCurrent = null; state.discussMessages = []; state.discussMembers = []; try { await loadDiscussOptions(state.discussCompanyId); await loadDiscussWorkspace(); renderDiscussConversation(); } catch (error) { showMessage($('#discuss-message'), error.message, true); } });
        $('#discuss-filter').addEventListener('change', () => loadDiscussWorkspace());
        $('#discuss-refresh').addEventListener('click', () => loadDiscussWorkspace());
        $('#discuss-new').addEventListener('click', openDiscussDialog);
        $('#discuss-kind').addEventListener('change', updateDiscussDialogMode);
        $('#discuss-form-company').addEventListener('change', async () => { try { await loadDiscussOptions(Number($('#discuss-form-company').value)); } catch (error) { setFormError('discuss-form-error', error.message); } });
        $('#discuss-form').addEventListener('submit', saveDiscussConversation);
        $('#discuss-compose').addEventListener('submit', sendDiscussMessage);
        $('#discuss-join').addEventListener('click', joinDiscussChannel);
        $('#discuss-star').addEventListener('click', () => setDiscussPreference('starred', !Boolean(state.discussCurrent?.starred)));
        $('#discuss-archive').addEventListener('click', () => setDiscussPreference('archived', !Boolean(state.discussCurrent?.archived)));
        $('#discuss-load-older').addEventListener('click', async () => { const box = $('#discuss-messages'); const oldHeight = box.scrollHeight; const beforeId = Number(state.discussMessages[0]?.id || 0); if (!beforeId) return; try { await loadDiscussConversation(Number(state.discussCurrent.id), beforeId); box.scrollTop = box.scrollHeight - oldHeight; } catch (error) { showMessage($('#discuss-message'), error.message, true); } });
        $('#appointments-company').addEventListener('change', () => { state.appointments.contactFilter = null; state.appointments.bookingFilter = null; initializeAppointments().catch((error) => showMessage($('#appointments-message'), error.message, true)); });
        $('#appointments-status').addEventListener('change', () => loadAppointmentBookings().catch((error) => showMessage($('#appointments-message'), error.message, true)));
        $('#appointments-refresh').addEventListener('click', () => initializeAppointments().catch((error) => showMessage($('#appointments-message'), error.message, true)));
        $('#calendar-company').addEventListener('change', () => { state.calendar.contactFilter = null; initializeCalendar().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-anchor').addEventListener('change', () => { if ($('#calendar-anchor').value) state.calendar.anchorDate = $('#calendar-anchor').value; loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-view-select').addEventListener('change', () => { state.calendar.view = $('#calendar-view-select').value; loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-prev').addEventListener('click', () => { shiftCalendarPeriod(-1); loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-next').addEventListener('click', () => { shiftCalendarPeriod(1); loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-today').addEventListener('click', () => { state.calendar.anchorDate = calendarDateInZone(new Date(), state.calendar.options.company?.timezone || 'Asia/Dhaka'); $('#calendar-anchor').value = state.calendar.anchorDate; loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-weekends').addEventListener('change', () => { state.calendar.showWeekends = $('#calendar-weekends').checked; renderCalendarGrid(); });
        $('#calendar-status').addEventListener('change', () => loadCalendarEvents().catch((error) => showMessage($('#calendar-message'), error.message, true)));
        $('#calendar-refresh').addEventListener('click', () => initializeCalendar().catch((error) => showMessage($('#calendar-message'), error.message, true)));
        $('#calendar-clear-contact-filter').addEventListener('click', () => { state.calendar.contactFilter = null; initializeCalendar().catch((error) => showMessage($('#calendar-message'), error.message, true)); });
        $('#calendar-event-company').addEventListener('change', () => loadCalendarOptions($('#calendar-event-company').value).catch((error) => setFormError('calendar-event-error', error.message)));
        $('#calendar-event-all-day').addEventListener('change', toggleCalendarAllDay);
        $('#calendar-event-recurrent').addEventListener('change', toggleCalendarRecurrence);
        $('#calendar-event-recurrence-frequency').addEventListener('change', () => { if (['weekly','monthly'].includes($('#calendar-event-recurrence-frequency').value)) setCalendarRecurrenceDefaults(); toggleCalendarRecurrence(); });
        $('#calendar-event-month-pattern').addEventListener('change', () => { if ($('#calendar-event-month-pattern').value === 'weekday') setCalendarRecurrenceDefaults(); toggleCalendarRecurrence(); });
        $('#calendar-event-recurrence-end-type').addEventListener('change', toggleCalendarRecurrence);
        $('#calendar-event-form').addEventListener('submit', saveCalendarEvent);
        for (const view of ['bookings','types','resources']) $(`#appointments-view-${view}`).addEventListener('click', () => { state.appointments.view = view; updateAppointmentView(); const add=$('#page-actions').querySelector('button');if(add)add.textContent=view==='types'?'New Appointment Type':view==='resources'?'New Resource':'New Booking'; const load=view==='types'?loadAppointmentTypes:view==='resources'?loadAppointmentResources:loadAppointmentBookings;load().catch((error)=>showMessage($('#appointments-message'),error.message,true)); });
        $('#appointment-type-form').addEventListener('submit', saveAppointmentType);
        $('#appointment-add-availability').addEventListener('click', () => addAppointmentAvailability());
        $('#appointment-add-question').addEventListener('click', () => addAppointmentQuestion());
        $('#appointment-assignment-target').addEventListener('change', toggleAppointmentAssignment);
        $('#appointment-type-company').addEventListener('change', () => loadAppointmentOptions($('#appointment-type-company').value).catch((error) => { $('#appointment-type-error').textContent = error.message; }));
        $('#appointment-resource-form').addEventListener('submit', saveAppointmentResource);
        $('#appointment-resource-company').addEventListener('change', () => loadAppointmentOptions($('#appointment-resource-company').value).catch((error) => { $('#appointment-resource-error').textContent = error.message; }));
        $('#appointment-booking-form').addEventListener('submit', saveAppointmentBooking);
        $('#appointment-booking-company').addEventListener('change', () => loadAppointmentBookingSetup().catch((error) => { $('#appointment-booking-error').textContent = error.message; }));
        $('#appointment-booking-type').addEventListener('change', () => loadAppointmentBookingType().catch((error) => { $('#appointment-booking-error').textContent = error.message; }));
        $('#appointment-booking-contact').addEventListener('change', () => { const contact=state.appointments.options.contacts?.find((row)=>String(row.id)===$('#appointment-booking-contact').value);if(contact){$('#appointment-booking-form').elements.name.value=contact.name||'';$('#appointment-booking-form').elements.email.value=contact.email||'';$('#appointment-booking-form').elements.phone.value=contact.phone||'';} });
        $('#appointment-booking-date').addEventListener('change', () => loadAppointmentBookingSlots().catch((error) => { $('#appointment-booking-error').textContent = error.message; }));
        $('#appointment-booking-guests').addEventListener('change', () => loadAppointmentBookingSlots().catch((error) => { $('#appointment-booking-error').textContent = error.message; }));
        $('#appointment-booking-slot').addEventListener('change', updateAppointmentSlotProviders);
        $('#todo-company').addEventListener('change', () => loadTodoWorkspace());
        $('#todo-state').addEventListener('change', () => loadTodoTasks().catch((error) => showMessage($('#todo-message'), error.message, true)));
        $('#todo-refresh').addEventListener('click', () => loadTodoWorkspace());
        $('#todo-load-more').addEventListener('click', async () => { $('#todo-load-more').disabled = true; try { await loadTodoTasks(true); } catch (error) { $('#todo-load-more').disabled = false; showMessage($('#todo-message'), error.message, true); } });
        $('#todo-search').addEventListener('input', () => { window.clearTimeout(bindEvents.todoSearchTimer); bindEvents.todoSearchTimer = window.setTimeout(() => loadTodoTasks().catch((error) => showMessage($('#todo-message'), error.message, true)), 250); });
        $('#todo-form').addEventListener('submit', saveTodoTask);
        $('#todo-stage-form').addEventListener('submit', saveTodoStage);
        $('#todo-assignees').addEventListener('change', enforceTodoAssigneeLimit);
        $('#todo-form-company').addEventListener('change', async () => {
            const companyId = $('#todo-form-company').value;
            $('#todo-contact').value = ''; $('#todo-source-model').value = ''; $('#todo-source-record').value = ''; $('#todo-source-search').value = '';
            try {
                await loadTodoOptions(companyId, '');
                $('#todo-form-company').value = String(state.todoOptions.company_id || '');
                $('#todo-form-stage').value = String(state.todoOptions.stages?.find((stage) => stage.stage_key === 'inbox')?.id || '');
            } catch (error) { setFormError('todo-form-error', error.message); }
        });
        $('#todo-source-model').addEventListener('change', async () => { const model = $('#todo-source-model').value; $('#todo-source-search').value = ''; $('#todo-source-record').value = ''; try { await loadTodoOptions($('#todo-form-company').value, model); $('#todo-source-model').value = model; $('#todo-source-record').value = ''; } catch (error) { setFormError('todo-form-error', error.message); } });
        $('#todo-source-search-button').addEventListener('click', searchTodoSources);
        $('#todo-source-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); searchTodoSources(); } });
        $('#crm-company').addEventListener('change', async () => {
            $('#crm-stage').value = '';
            try { await loadCrmOptions($('#crm-company').value); await loadCrmRecords(); }
            catch (error) { showMessage($('#crm-message'), error.message, true); }
        });
        $('#crm-stage').addEventListener('change', () => loadCrmRecords().catch((error) => showMessage($('#crm-message'), error.message, true)));
        $('#crm-type').addEventListener('change', () => loadCrmRecords().catch((error) => showMessage($('#crm-message'), error.message, true)));
        $('#crm-state').addEventListener('change', () => loadCrmRecords().catch((error) => showMessage($('#crm-message'), error.message, true)));
        $('#refresh-crm').addEventListener('click', () => loadCrmRecords().catch((error) => showMessage($('#crm-message'), error.message, true)));
        $('#crm-config-open').addEventListener('click', openCrmConfiguration);
        $('#crm-config-stages-tab').addEventListener('click', () => setCrmConfigurationView('stages'));
        $('#crm-config-teams-tab').addEventListener('click', () => setCrmConfigurationView('teams'));
        $('#crm-stage-new').addEventListener('click', () => openCrmStage());
        $('#crm-team-new').addEventListener('click', () => openCrmTeam());
        $('#crm-stage-form').addEventListener('submit', saveCrmConfigurationRecord);
        $('#crm-team-form').addEventListener('submit', saveCrmConfigurationRecord);
        $('#crm-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.crmSearchTimer);
            bindEvents.crmSearchTimer = window.setTimeout(() => loadCrmRecords().catch((error) => showMessage($('#crm-message'), error.message, true)), 250);
        });
        $('#crm-form').addEventListener('submit', saveCrmRecord);
        $('#crm-form').elements.stage_id.addEventListener('change', () => {
            const stage = state.crmOptions.stages?.find((row) => Number(row.id) === Number($('#crm-form').elements.stage_id.value));
            if (stage) $('#crm-form').elements.probability.value = String(stage.probability);
        });
        $('#crm-form').elements.record_type.addEventListener('change', () => {
            const lead = state.currentCrmLead;
            $('#crm-convert').hidden = Boolean(lead && lead.state === 'open' && $('#crm-form').elements.record_type.value !== 'lead');
            $('#crm-mark-won').hidden = !lead || lead.state !== 'open' || $('#crm-form').elements.record_type.value !== 'opportunity';
            $('#crm-mark-lost').hidden = !lead || lead.state !== 'open' || $('#crm-form').elements.record_type.value !== 'opportunity';
            $('#crm-lost-reason-wrap').hidden = !lead || lead.state !== 'open' || $('#crm-form').elements.record_type.value !== 'opportunity';
        });
        $('#crm-convert').addEventListener('click', convertCrmRecord);
        $('#crm-new-quotation').addEventListener('click', createQuotationFromOpportunity);
        $('#crm-mark-won').addEventListener('click', () => closeCrmOpportunity('won'));
        $('#crm-mark-lost').addEventListener('click', () => closeCrmOpportunity('lost'));
        $('#crm-add-activity').addEventListener('click', addCrmActivity);
        $('#sales-company').addEventListener('change', async () => {
            try { await loadSalesOptions($('#sales-company').value); await loadSalesRecords(); }
            catch (error) { showMessage($('#sales-message'), error.message, true); }
        });
        $('#sales-customer').addEventListener('change', () => updateSalesContractOptions($('#sales-customer').value));
        $('#commission-plan-new').addEventListener('click', () => openCommissionPlanEditor().catch((error) => showMessage($('#commission-plans-message'), error.message, true)));
        $('#commission-plan-add-rule').addEventListener('click', () => appendCommissionRule());
        $('#commission-plan-form').addEventListener('submit', saveCommissionPlan);
        $('#commission-plan-company').addEventListener('change', async (event) => {
            const currentValues = [...$('#commission-plan-rules').rows].map((row) => Object.fromEntries([...row.querySelectorAll('[data-field]')].map((field) => [field.dataset.field, field.type === 'checkbox' ? field.checked : field.value])));
            $('#commission-plan-default-product').value = '';
            try {
                const options = await loadCommissionPlanOptions(event.currentTarget.value);
                const rows = [...$('#commission-plan-rules').rows];
                rows.forEach((row, index) => {
                    const optionMap = { product_category_id: options.product_categories, product_id: options.products, sale_order_template_id: options.sale_order_templates, pricelist_id: options.pricelists };
                    for (const [field, items] of Object.entries(optionMap)) { const select = row.querySelector(`[data-field="${field}"]`); const wanted = currentValues[index]?.[field] || ''; const available = (items || []).some((item) => String(item.id) === String(wanted)); fillSelect(select, items || [], { placeholder: 'Select…', keepValue: available ? wanted : '' }); }
                });
            } catch (error) { setFormError('commission-plan-error', error.message); }
        });
        $('#sales-state').addEventListener('change', () => loadSalesRecords().catch((error) => showMessage($('#sales-message'), error.message, true)));
        $('#refresh-sales').addEventListener('click', () => loadSalesRecords().catch((error) => showMessage($('#sales-message'), error.message, true)));
        $('#sales-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.salesSearchTimer);
            bindEvents.salesSearchTimer = window.setTimeout(() => loadSalesRecords().catch((error) => showMessage($('#sales-message'), error.message, true)), 250);
        });
        $('#sales-record-company').addEventListener('change', async () => {
            const companyId = $('#sales-record-company').value;
            try { await loadSalesOptions(companyId); $('#sales-form').elements.company_id.value = companyId; $('#sales-customer').value = ''; $('#sales-invoice-contact').value = ''; $('#sales-delivery-contact').value = ''; updateSalesContractOptions(''); $('#sales-template').value = ''; $('#sales-lines').replaceChildren(); if ($('#sales-form').dataset.id === '') addSalesLine(); }
            catch (error) { setFormError('sales-form-error', error.message); }
        });
        $('#sales-template').addEventListener('change', (event) => {
            const select=event.currentTarget; const previous=select.dataset.previousTemplateId||'';
            if(!select.value){select.dataset.previousTemplateId='';return;}
            const hasSelectedLines=$$('.sales-line',$('#sales-lines')).some((row)=>Boolean(row.querySelector('[name="product_id"]').value));
            applySalesTemplate(select.value,hasSelectedLines).then((applied)=>{if(applied)select.dataset.previousTemplateId=select.value;else select.value=previous;}).catch((error)=>{select.value=previous;setFormError('sales-form-error',error.message);});
        });
        $('#sales-template-company').addEventListener('change',async()=>{try{await loadSalesOptions($('#sales-template-company').value);await loadSalesTemplates();}catch(error){showMessage($('#sales-template-message'),error.message,true);}});
        $('#sales-template-new').addEventListener('click',()=>editSalesTemplate().catch((error)=>showMessage($('#sales-template-message'),error.message,true)));
        $('#sales-template-add-line').addEventListener('click',()=>appendSalesTemplateLine());
        $('#sales-template-form').addEventListener('submit',saveSalesTemplate);
        $('#sales-template-form-company').addEventListener('change',async(event)=>{const companyId=event.currentTarget.value;try{await loadSalesOptions(companyId);fillSelect($('#sales-template-pricelist'),state.salesOptions.pricelists||[],{placeholder:'Use customer or company default',label:(row)=>`${row.name} · ${row.currency_code}`});$('#sales-template-lines').replaceChildren();appendSalesTemplateLine();}catch(error){setFormError('sales-template-error',error.message);}});
        $('#sales-customer').addEventListener('change', () => {
            const customer = state.salesOptions.contacts?.find((item) => Number(item.id) === Number($('#sales-customer').value));
            if (customer) { applySalesCustomerDefaults(customer); $('#sales-invoice-contact').value = String(customer.id); $('#sales-delivery-contact').value = String(customer.id); }
        });
        $('#sales-opportunity').addEventListener('change', () => {
            const opportunity = state.salesOptions.opportunities?.find((item) => Number(item.id) === Number($('#sales-opportunity').value));
            if (opportunity?.partner_contact_id) $('#sales-customer').value = String(opportunity.partner_contact_id);
            const customer = state.salesOptions.contacts?.find((item) => Number(item.id) === Number($('#sales-customer').value));
            if (customer) applySalesCustomerDefaults(customer);
        });
        $('#sales-pricelist').addEventListener('change', () => {
            const pricelist = state.salesOptions.pricelists?.find((item) => Number(item.id) === Number($('#sales-pricelist').value));
            if (pricelist) $('#sales-currency').value = pricelist.currency_code;
        });
        $('#add-sales-line').addEventListener('click', () => addSalesLine());
        $('#sales-product-catalog').addEventListener('click', async () => {
            try { await openSharedProductCatalog({ channel: 'sales', companyId: $('#sales-company').value || state.defaultCompanyId, title: 'Sales Product Catalog', description: 'Choose a shared Inventory product to start a new quotation.', onSelect: can('sales.write') ? (product) => openSalesOrder(null, { company_id: $('#sales-company').value || state.defaultCompanyId, product_id: product.id, catalog_product: product }) : null }); }
            catch (error) { showMessage($('#sales-message'), error.message, true); }
        });
        $('#sales-form').addEventListener('submit', saveSalesOrder);
        $('#sales-send').addEventListener('click', () => updateSalesState('send'));
        $('#sales-confirm').addEventListener('click', () => updateSalesState('confirm'));
        $('#sales-cancel').addEventListener('click', () => updateSalesState('cancel'));
        $('#sales-invoice').addEventListener('click', createInvoiceFromSales);
        $('#sales-tax-form').addEventListener('submit', saveSalesTax);
        $('#sales-tax-company').addEventListener('change', async () => {
            try { await loadSalesOptions($('#sales-tax-company').value); }
            catch (error) { setFormError('sales-tax-error', error.message); }
        });
        $('#purchase-company').addEventListener('change', () => initializePurchase($('#purchase-company').value).catch((error) => showMessage($('#purchase-message'), error.message, true)));
        $('#purchase-vendor').addEventListener('change', () => updatePurchaseContractOptions($('#purchase-vendor').value));
        $('#purchase-state').addEventListener('change', () => loadPurchaseRecords().catch((error) => showMessage($('#purchase-message'), error.message, true)));
        $('#refresh-purchase').addEventListener('click', () => {
            const task = state.purchaseView === 'bills' ? loadVendorBills() : loadPurchaseRecords();
            task.catch((error) => showMessage($('#purchase-message'), error.message, true));
        });
        $('#purchase-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.purchaseSearchTimer);
            bindEvents.purchaseSearchTimer = window.setTimeout(() => {
                const task = state.purchaseView === 'bills' ? loadVendorBills() : loadPurchaseRecords();
                task.catch((error) => showMessage($('#purchase-message'), error.message, true));
            }, 250);
        });
        $('#purchase-view-orders').addEventListener('click', () => setPurchaseView('orders'));
        $('#purchase-view-bills').addEventListener('click', () => setPurchaseView('bills'));
        $('#purchase-product-catalog').addEventListener('click', async () => {
            const companyId = $('#purchase-company').value || state.defaultCompanyId;
            try { await openSharedProductCatalog({ channel: 'purchase', companyId, title: 'Purchase Product Catalog', description: 'Choose a product from the shared Inventory catalog.', onSelect: can('purchase.write') ? (product) => openPurchaseOrder(null, { company_id: companyId, product_id: product.id, catalog_product: product }) : null }); }
            catch (error) { showMessage($('#purchase-message'), error.message, true); }
        });
        $('#purchase-line-add').addEventListener('click', () => addPurchaseLine());
        $('#purchase-form').addEventListener('submit', savePurchaseOrder);
        $('#purchase-form-company').addEventListener('change', async () => {
            const companyId = $('#purchase-form-company').value;
            if (!companyId) return;
            try {
                await loadPurchaseOptions(companyId);
                $('#purchase-form-company').value = companyId;
                $('#purchase-currency').value = state.purchaseOptions.currency_code || '';
                $('#purchase-vendor').value = '';
                updatePurchaseContractOptions('');
                $('#purchase-terms').value = '';
                $('#purchase-buyer').value = state.user?.id && (state.purchaseOptions.buyers || []).some((row) => Number(row.id) === Number(state.user.id)) ? String(state.user.id) : '';
                $('#purchase-warehouse').value = String(state.purchaseOptions.warehouses?.[0]?.id || '');
            } catch (error) { setFormError('purchase-form-error', error.message); }
        });
        $('#purchase-send').addEventListener('click', sendPurchaseOrder);
        $('#purchase-confirm').addEventListener('click', confirmPurchaseOrder);
        $('#purchase-create-bill').addEventListener('click', createVendorBill);
        $('#purchase-edit').addEventListener('click', () => state.currentPurchaseOrder && openPurchaseOrder(Number(state.currentPurchaseOrder.id)));
        $('#purchase-tax-form').addEventListener('submit', savePurchaseTax);
        $('#purchase-tax-company').addEventListener('change', async () => {
            try {
                const result = await api('/api/v1/purchase/options?company_id=' + encodeURIComponent($('#purchase-tax-company').value));
                const accounts = (result.options?.accounts || []).filter((row) => ['asset_current','asset_non_current'].includes(row.account_type));
                fillSelect($('#purchase-tax-account'), accounts, { placeholder: 'No recoverable tax account', label: (row) => `${row.code} · ${row.name}` });
            } catch (error) { setFormError('purchase-tax-error', error.message); }
        });
        $('#vendor-bill-post').addEventListener('click', postVendorBill);
        $('#vendor-credit-note-create').addEventListener('click', openVendorCreditNote);
        $('#vendor-credit-note-form').addEventListener('submit', saveVendorCreditNote);$('#vendor-credit-apply-form').addEventListener('submit',applyVendorCredit);$('#vendor-credit-apply-bill').addEventListener('change',updateVendorCreditApplicationAmount);$('#vendor-credit-apply-search').addEventListener('input',()=>{window.clearTimeout(state.vendorCreditApplicationSearchTimer);state.vendorCreditApplicationSearchTimer=window.setTimeout(()=>searchVendorCreditBills(),250);});$('#vendor-credit-apply-search').addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();window.clearTimeout(state.vendorCreditApplicationSearchTimer);searchVendorCreditBills();}});$('#vendor-credit-apply-dialog').addEventListener('close',()=>{state.vendorCreditApplication=null;});
        $('#vendor-credit-refund-form').addEventListener('submit', registerVendorCreditRefund);
        $('#vendor-credit-refund-match-form').addEventListener('submit', reconcileVendorCreditRefund);
        $('#vendor-bill-pay').addEventListener('click', openVendorPayment);
        $('#vendor-payment-form').addEventListener('submit', saveVendorPayment);
        $('#vendor-bank-match-form').addEventListener('submit', reconcileVendorPayment);
        $('#invoice-company').addEventListener('change', () => {
            const companyId=$('#invoice-company').value;
            initializeInvoicing(companyId).catch((error)=>showMessage($('#invoicing-message'),error.message,true));
        });
        $('#invoice-new').addEventListener('click', () => openDirectInvoice().catch((error) => setFormError('direct-invoice-error', error.message)));
        $('#invoice-product-catalog').addEventListener('click', async () => {
            try { await openSharedProductCatalog({ channel: 'invoice', companyId: $('#invoice-company').value || state.defaultCompanyId, title: 'Invoice Product Catalog', description: 'Choose a service from the shared product master to start a direct customer invoice.', onSelect: can('invoicing.write') && can('contacts.read') ? (product) => openDirectInvoice({ companyId: $('#invoice-company').value || state.defaultCompanyId, product_id: product.id, catalog_product: product }) : null }); }
            catch (error) { showMessage($('#invoicing-message'), error.message, true); }
        });
        $('#direct-invoice-form').addEventListener('submit', saveDirectInvoice);
        $('#direct-invoice-add-line').addEventListener('click', () => addDirectInvoiceLine());
        $('#direct-invoice-customer').addEventListener('change', () => fillDirectInvoiceContacts($('#direct-invoice-customer').value));
        $('#direct-invoice-company').addEventListener('change', async () => {
            const companyId = $('#direct-invoice-company').value;
            setFormError('direct-invoice-error');
            try {
                await initializeInvoicing(companyId);
                fillSelect($('#direct-invoice-customer'), state.invoicingOptions.customers || [], { placeholder: 'Select customer', label: (item) => `${item.name}${item.email ? ` · ${item.email}` : ''}` });
                fillDirectInvoiceContacts('');
                $('#direct-invoice-lines').replaceChildren();
                addDirectInvoiceLine();
            } catch (error) { setFormError('direct-invoice-error', error.message); }
        });
        $('#invoice-state').addEventListener('change',()=>loadInvoices().catch((error)=>showMessage($('#invoicing-message'),error.message,true)));
        $('#refresh-invoices').addEventListener('click',()=>loadInvoices().catch((error)=>showMessage($('#invoicing-message'),error.message,true)));
        $('#invoice-search').addEventListener('input',()=>{
            window.clearTimeout(bindEvents.invoiceSearchTimer);
            bindEvents.invoiceSearchTimer=window.setTimeout(()=>loadInvoices().catch((error)=>showMessage($('#invoicing-message'),error.message,true)),250);
        });
        $('#invoice-post').addEventListener('click',postInvoice);
        $('#invoice-print').addEventListener('click',printInvoice);
        $('#invoice-discard-credit-note').addEventListener('click',discardCreditNote);
        $('#invoice-register-payment').addEventListener('click',openRegisterPayment);
        $('#invoice-register-credit-note-refund').addEventListener('click',openCreditNoteRefund);
        $('#invoice-apply-credit-note').addEventListener('click',openCustomerCreditApplication);
        $('#customer-credit-apply-form').addEventListener('submit',applyCustomerCredit);
        $('#customer-credit-apply-invoice').addEventListener('change',updateCustomerCreditApplicationAmount);
        $('#customer-credit-apply-search').addEventListener('input',()=>{window.clearTimeout(state.customerCreditApplicationSearchTimer);state.customerCreditApplicationSearchTimer=window.setTimeout(()=>searchCustomerCreditInvoices(),250);});
        $('#customer-credit-apply-search').addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();window.clearTimeout(state.customerCreditApplicationSearchTimer);searchCustomerCreditInvoices();}});
        $('#customer-credit-apply-dialog').addEventListener('close',()=>{state.customerCreditApplication=null;});
        $('#credit-note-refund-form').addEventListener('submit',registerCreditNoteRefund);
        $('#credit-note-refund-reconcile-form').addEventListener('submit',reconcileCreditNoteRefund);
        $('#invoice-create-credit-note').addEventListener('click',openCreditNoteDialog);
        $('#credit-note-form').addEventListener('submit',createCreditNote);
        $('#accounting-company').addEventListener('change',()=>initializeAccounting($('#accounting-company').value).catch((error)=>showMessage($('#accounting-message'),error.message,true)));
        $$('[data-accounting-view]').forEach((button)=>button.addEventListener('click',()=>setAccountingView(button.dataset.accountingView)));
        $$('[data-accounting-destination]').forEach((button)=>button.addEventListener('click',()=>openAccountingDestination(button.dataset.accountingDestination)));
        $('#accounting-report-type').addEventListener('change',()=>{syncAccountingReportControls();loadAccountingReport(0,false);});
        $('#accounting-account').addEventListener('change',()=>{if($('#accounting-report-type').value==='general_ledger')loadAccountingReport(0,false);});
        $('#accounting-run').addEventListener('click',()=>loadAccountingReport(0,false));
        $('#accounting-report-more').addEventListener('click',async(event)=>{const button=event.currentTarget;button.disabled=true;await loadAccountingReport(state.accountingRecords.length,true);});
        $('#accounting-entry-state').addEventListener('change',()=>loadManualJournalEntries(0,false).catch((error)=>showMessage($('#accounting-entry-message'),error.message,true)));
        $('#accounting-entry-search').addEventListener('input',()=>{window.clearTimeout(bindEvents.manualEntrySearchTimer);bindEvents.manualEntrySearchTimer=window.setTimeout(()=>loadManualJournalEntries(0,false).catch((error)=>showMessage($('#accounting-entry-message'),error.message,true)),250);});
        $('#accounting-entry-refresh').addEventListener('click',()=>loadManualJournalEntries(0,false).catch((error)=>showMessage($('#accounting-entry-message'),error.message,true)));
        $('#accounting-entry-more').addEventListener('click',async(event)=>{event.currentTarget.disabled=true;try{await loadManualJournalEntries(state.manualEntries.length,true);}catch(error){showMessage($('#accounting-entry-message'),error.message,true);event.currentTarget.disabled=false;}});
        $('#bank-statement-import-form').addEventListener('submit',importBankStatement);
        $('#bank-statement-refresh').addEventListener('click',()=>loadBankStatements(0,false).catch((error)=>showMessage($('#bank-statement-message'),error.message,true)));
        $('#bank-statement-more').addEventListener('click',async(event)=>{event.currentTarget.disabled=true;try{await loadBankStatements(state.bankStatements.length,true);}catch(error){showMessage($('#bank-statement-message'),error.message,true);event.currentTarget.disabled=false;}});
        $('#bank-statement-detail-close').addEventListener('click',()=>{$('#bank-statement-detail').hidden=true;state.bankStatementDetail=null;});
        $('#bank-statement-lines-more').addEventListener('click',async(event)=>{const detail=state.bankStatementDetail;if(!detail)return;event.currentTarget.disabled=true;try{await openBankStatement(Number(detail.statement.id),true);}catch(error){showMessage($('#bank-statement-message'),error.message,true);event.currentTarget.disabled=false;}});
        $('#bank-statement-categorize-form').addEventListener('submit',categorizeBankStatementLine);
        $('#accounting-entry-form').addEventListener('submit',saveManualJournalEntry);
        $('#accounting-entry-add-line').addEventListener('click',()=>addManualJournalLine());
        $('#accounting-entry-post').addEventListener('click',postManualJournalEntry);
        $('#accounting-entry-discard').addEventListener('click',discardManualJournalEntry);
        $('#accounting-entry-reverse').addEventListener('click',openAccountingReversal);
        $('#accounting-reversal-form').addEventListener('submit',reverseManualJournalEntry);
        $('#accounting-entry-company').addEventListener('change',async()=>{if($('#accounting-entry-form').dataset.id)return;try{await initializeAccounting($('#accounting-entry-company').value);$('#accounting-entry-lines').replaceChildren();$('#accounting-entry-journal').value=String(state.manualEntryOptions.journals?.[0]?.id||'');addManualJournalLine();addManualJournalLine();renderManualEntryTotals();}catch(error){setFormError('accounting-entry-form-error',error.message);}});
        $('#payment-form').addEventListener('submit',registerCustomerPayment);
        $('#bank-reconcile-form').addEventListener('submit',reconcileCustomerPayment);
        $('#account-setup-form').addEventListener('submit',saveAccountingAccount);
        $('#journal-setup-form').addEventListener('submit',saveSalesJournal);
        $('#journal-setup-type').addEventListener('change',syncJournalSetupFields);
        $('#account-setup-company').addEventListener('change',()=>{
            const companyId=$('#account-setup-company').value;
            if(companyId)initializeInvoicing(companyId).catch((error)=>showMessage($('#invoicing-message'),error.message,true));
        });
        $('#journal-setup-company').addEventListener('change',()=>{
            const companyId=$('#journal-setup-company').value;
            if(companyId)initializeInvoicing(companyId).catch((error)=>showMessage($('#invoicing-message'),error.message,true));
        });
        $('#new-contact').addEventListener('click', () => openContact());
        $('#create-contact-only').addEventListener('click', () => openContact());
        $('#refresh-contacts').addEventListener('click', () => loadContacts().catch((error) => showMessage($('#contacts-message'), error.message, true)));
        $('#export-contacts').addEventListener('click', async () => {
            const button = $('#export-contacts'); button.disabled = true; showMessage($('#contacts-message'));
            try { const filters = { q: $('#contact-search').value.trim(), role: $('#contact-role').value }; if ($('#contact-company').value) filters.company_id = $('#contact-company').value; await downloadMasterData('contacts', filters); }
            catch (error) { showMessage($('#contacts-message'), error.message, true); }
            finally { button.disabled = false; }
        });
        $('#contact-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.searchTimer);
            bindEvents.searchTimer = window.setTimeout(() => loadContacts().catch((error) => showMessage($('#contacts-message'), error.message, true)), 250);
        });
        $('#contact-role').addEventListener('change', () => loadContacts().catch((error) => showMessage($('#contacts-message'), error.message, true)));
        $('#contact-company').addEventListener('change', () => loadContacts().catch((error) => showMessage($('#contacts-message'), error.message, true)));
        $('#inventory-company').addEventListener('change', () => {
            const loadOptions = state.page === 'products' || !can('inventory.read') ? loadProductCatalogOptions : loadInventoryOptions;
            loadOptions($('#inventory-company').value).then(async () => {
                if (state.page === 'products' || !can('inventory.read')) { selectInventoryView('products'); return; }
                await loadProducts();
                if (state.inventoryView === 'moves') await loadStockMoves();
                if (state.inventoryView === 'levels') await loadStockLevels();
                if (state.inventoryView === 'cycle-counts') { await loadInventoryCycleCountOptions($('#inventory-company').value); await loadInventoryCycleCounts(); }
                if (state.inventoryView === 'valuation') await loadInventoryValuation();
                if (state.inventoryView === 'accounting-setup') await loadInventoryAccounting();
                if (state.inventoryView === 'warehouses') renderInventoryWarehouses();
                if (state.inventoryView === 'master-data') renderInventoryMasterData();
                selectInventoryView(state.inventoryView);
            }).catch((error) => showMessage($('#inventory-message'), error.message, true));
        });
        $('#product-status').addEventListener('change', () => loadProducts().catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#import-products').addEventListener('click', openProductImport);
        $('#product-import-form').addEventListener('submit', importProducts);
        $('#download-product-template').addEventListener('click', async () => {
            const button = $('#download-product-template');
            const companyId = $('#product-import-company').value;
            if (!companyId) { setFormError('product-import-error', 'Choose a company before downloading the template.'); return; }
            button.disabled = true;
            try { await downloadProductImportTemplate(companyId); }
            catch (error) { setFormError('product-import-error', error.message); }
            finally { button.disabled = false; }
        });
        $('#export-products').addEventListener('click', async () => {
            const button = $('#export-products'); button.disabled = true; showMessage($('#inventory-message'));
            try { await downloadMasterData('products', { company_id: $('#inventory-company').value, status: $('#product-status').value, q: $('#product-search').value.trim() }); }
            catch (error) { showMessage($('#inventory-message'), error.message, true); }
            finally { button.disabled = false; }
        });
        $('#refresh-inventory').addEventListener('click', () => {
            const task = state.inventoryView === 'products' ? loadProducts() : state.inventoryView === 'moves' ? loadStockMoves() : state.inventoryView === 'cycle-counts' ? loadInventoryCycleCounts() : state.inventoryView === 'valuation' ? loadInventoryValuation() : state.inventoryView === 'accounting-setup' ? loadInventoryAccounting() : state.inventoryView === 'warehouses' ? loadInventoryOptions($('#inventory-company').value).then(renderInventoryWarehouses) : state.inventoryView === 'master-data' ? loadInventoryOptions($('#inventory-company').value).then(renderInventoryMasterData) : loadStockLevels();
            task.catch((error) => showMessage($('#inventory-message'), error.message, true));
        });
        $('#warehouse-search').addEventListener('input', () => renderInventoryWarehouses());
        $('#warehouse-form').addEventListener('submit', createWarehouse);
        $('#inventory-location-form').addEventListener('submit', saveInventoryLocation);
        $('#inventory-location-warehouse').addEventListener('change', () => fillLocationParents($('#inventory-location-warehouse').value));
        $('#inventory-location-usage').addEventListener('change', syncInventoryLocationFrequency);
        $('#inventory-master-search').addEventListener('input', () => renderInventoryMasterData());
        $('#inventory-category-form').addEventListener('submit', saveInventoryMasterForm);
        $('#inventory-uom-category-form').addEventListener('submit', saveInventoryMasterForm);
        $('#inventory-uom-form').addEventListener('submit', saveInventoryMasterForm);
        $('#inventory-new-category').addEventListener('click', openInventoryCategoryCreate);
        $('#inventory-new-uom-category').addEventListener('click', openInventoryUomCategoryCreate);
        $('#inventory-new-uom').addEventListener('click', openInventoryUomCreate);
        $('#inventory-uom-type').addEventListener('change', syncInventoryUomFactor);
        $('#product-view-catalog').addEventListener('click', () => { state.productCatalogView = 'catalog'; renderProducts(); });
        $('#product-view-table').addEventListener('click', () => { state.productCatalogView = 'table'; renderProducts(); });
        $('#products-catalog-grid').addEventListener('click', (event) => {
            const button = event.target.closest('[data-open-product]');
            if (button) openProduct(Number(button.dataset.openProduct));
        });
        $('#shared-product-catalog-grid').addEventListener('click', (event) => {
            const button = event.target.closest('[data-shared-catalog-select]');
            if (button) selectSharedCatalogProduct(Number(button.dataset.sharedCatalogSelect));
        });
        $('#shared-product-catalog-search').addEventListener('input', () => {
            window.clearTimeout(state.sharedCatalogSearchTimer);
            state.sharedCatalogSearchTimer = window.setTimeout(() => {
                if (!state.sharedProductCatalog) return;
                state.sharedProductCatalog.query = $('#shared-product-catalog-search').value.trim();
                loadSharedProductCatalog(true);
            }, 220);
        });
        $('#shared-product-catalog-search').addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault(); window.clearTimeout(state.sharedCatalogSearchTimer);
            if (state.sharedProductCatalog) { state.sharedProductCatalog.query = $('#shared-product-catalog-search').value.trim(); loadSharedProductCatalog(true); }
        });
        $('#shared-product-catalog-category').addEventListener('change', () => {
            if (!state.sharedProductCatalog) return;
            state.sharedProductCatalog.categoryId = $('#shared-product-catalog-category').value;
            loadSharedProductCatalog(true);
        });
        $('#shared-product-catalog-more').addEventListener('click', () => loadSharedProductCatalog(false));
        $('#shared-product-catalog-dialog').addEventListener('close', () => { state.sharedProductCatalog = null; });
        $('#product-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.productSearchTimer);
            bindEvents.productSearchTimer = window.setTimeout(() => loadProducts().catch((error) => showMessage($('#inventory-message'), error.message, true)), 250);
        });
        $('#product-form').addEventListener('submit', saveProduct);
        $$('#product-tabs [data-product-tab]').forEach((tab) => tab.addEventListener('click', () => selectProductTab(tab.dataset.productTab)));
        $('#product-form').addEventListener('input', () => {
            if ($('#product-form').dataset.id) $('#product-form').dataset.dirty = '1';
            updateProductFlowCards();
        });
        $('#product-form').addEventListener('change', () => {
            if ($('#product-form').dataset.id) $('#product-form').dataset.dirty = '1';
            updateProductFlowCards();
        });
        $('#product-sales-flow').addEventListener('click', () => startProductOrderFlow('sales').catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#product-purchase-flow').addEventListener('click', () => startProductOrderFlow('purchase').catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $$('.inventory-view-button').forEach((button) => button.addEventListener('click', () => selectInventoryView(button.dataset.inventoryView)));
        $('#move-status').addEventListener('change', () => loadStockMoves().catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#move-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.moveSearchTimer);
            bindEvents.moveSearchTimer = window.setTimeout(() => loadStockMoves().catch((error) => showMessage($('#inventory-message'), error.message, true)), 250);
        });
        $('#valuation-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.valuationSearchTimer);
            bindEvents.valuationSearchTimer = window.setTimeout(() => loadInventoryValuation().catch((error) => showMessage($('#inventory-message'), error.message, true)), 250);
        });
        $('#cycle-count-search').addEventListener('input', () => {
            window.clearTimeout(bindEvents.cycleCountSearchTimer);
            bindEvents.cycleCountSearchTimer = window.setTimeout(() => loadInventoryCycleCounts().catch((error) => showMessage($('#inventory-message'), error.message, true)), 250);
        });
        $('#cycle-count-status').addEventListener('change', () => loadInventoryCycleCounts().catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#cycle-counts-more').addEventListener('click', async (event) => {
            const button = event.currentTarget; button.disabled = true;
            try { await loadInventoryCycleCounts(true); }
            catch (error) { button.disabled = false; showMessage($('#inventory-message'), error.message, true); }
        });
        $('#inventory-cycle-count-create-form').addEventListener('submit', createInventoryCycleCount);
        $('#cycle-count-company').addEventListener('change', async () => {
            try {
                const companyId = $('#cycle-count-company').value;
                await loadInventoryOptions(companyId); await loadInventoryCycleCountOptions(companyId); await loadProducts();
                $('#inventory-company').value = companyId;
                populateCycleCountLocations(companyId);
                await loadInventoryCycleCounts();
            } catch (error) { setFormError('cycle-count-create-error', error.message); }
        });
        $('#cycle-count-add-line-form').addEventListener('submit', addInventoryCycleCountLine);
        $('#cycle-count-product-catalog').addEventListener('click', async () => {
            try { await openSharedProductCatalog({ channel: 'inventory', productType: 'goods', companyId: $('#cycle-count-company').value || state.currentInventoryCycleCount?.company_id || state.defaultCompanyId, onSelect: (selected) => { const products = (state.products || []).filter((product) => product.product_type === 'goods' && product.active !== 0 && product.active !== '0'); if (!products.some((item) => Number(item.id) === Number(selected.id))) products.push(selected); fillSelect($('#cycle-count-line-product'), products, { placeholder: 'Select Goods product', keepValue: String(selected.id), label: (product) => `${product.name}${product.internal_reference ? ` · ${product.internal_reference}` : ''}` }); $('#cycle-count-line-product').value = String(selected.id); } }); }
            catch (error) { setFormError('cycle-count-detail-error', error.message); }
        });
        $('#cycle-count-validate').addEventListener('click', validateInventoryCycleCount);
        $('#cycle-count-cancel').addEventListener('click', cancelInventoryCycleCount);
        $('#inventory-valuation-more').addEventListener('click', () => loadInventoryValuation(true).catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#inventory-closing-date').addEventListener('change', () => loadInventoryClosingReport().catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#inventory-closing-generate').addEventListener('click', generateInventoryClosing);
        $('#inventory-closing-refresh').addEventListener('click', () => loadInventoryClosingReport().catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#inventory-closing-history-more').addEventListener('click', () => loadInventoryClosingReport(true).catch((error) => showMessage($('#inventory-message'), error.message, true)));
        $('#inventory-closing-post').addEventListener('click', postInventoryClosing);
        $('#inventory-closing-discard').addEventListener('click', discardInventoryClosing);
        $('#stock-move-form').addEventListener('submit', saveStockMove);
        $('#stock-move-return').addEventListener('click', () => { const move = state.currentStockMove; $('#stock-move-dialog').close(); if (move) openCustomerReturnDialog(move, false); });
        $('#stock-move-supplier-return').addEventListener('click', () => { const move = state.currentStockMove; $('#stock-move-dialog').close(); if (move) openCustomerReturnDialog(move, true); });
        $('#customer-return-form').addEventListener('submit', createCustomerReturn);
        $('#move-product').addEventListener('change', () => {
            updateMoveProductOptions(); $('#move-lines').replaceChildren();
            if ($('#move-product').value) addMoveLine();
        });
        $('#move-product-catalog').addEventListener('click', async () => {
            try { await openSharedProductCatalog({ channel: 'inventory', productType: 'goods', companyId: $('#move-company').value || state.defaultCompanyId, onSelect: (selected) => { state.moveProducts = state.moveProducts || []; if (!state.moveProducts.some((item) => Number(item.id) === Number(selected.id))) state.moveProducts.push(selected); fillSelect($('#move-product'), state.moveProducts, { placeholder: 'Select a goods product', keepValue: String(selected.id), label: (row) => `${row.name}${row.internal_reference ? ` · ${row.internal_reference}` : ''}` }); $('#move-product').value = String(selected.id); $('#move-product').dispatchEvent(new Event('change', { bubbles: true })); } }); }
            catch (error) { setFormError('stock-move-form-error', error.message); }
        });
        $('#move-company').addEventListener('change', async () => {
            try {
                const companyId = $('#move-company').value;
                await loadInventoryOptions(companyId); await loadMoveProducts(companyId);
                $('#move-source').value = ''; $('#move-destination').value = ''; $('#move-lines').replaceChildren();
                updateMoveProductOptions();
            } catch (error) { setFormError('stock-move-form-error', error.message); }
        });
        $('#move-company').addEventListener('change', () => { $('#inventory-company').value = $('#move-company').value; });
        $('#add-move-line').addEventListener('click', () => {
            const product = state.moveProducts.find((row) => Number(row.id) === Number($('#move-product').value));
            if (product?.tracking !== 'none') addMoveLine();
        });
        $('#stock-move-form').elements.demand_quantity.addEventListener('change', () => {
            const product = state.moveProducts.find((row) => Number(row.id) === Number($('#move-product').value));
            const lines = $$('.move-line', $('#move-lines'));
            if (product?.tracking === 'none' && lines.length === 1) lines[0].querySelector('[name="quantity"]').value = $('#stock-move-form').elements.demand_quantity.value;
        });
        $('#contact-company-select').addEventListener('change', (event) => {
            const creationOnly = state.page === 'contacts' && !can('contacts.read');
            loadContactOptions(event.target.value, creationOnly).catch((error) => setFormError('contact-form-error', error.message));
        });
        $('#contact-commission-plan-new').addEventListener('click', () => {
            const form = $('#contact-form');
            if (state.lookup.commission_manage_access !== true || state.lookup.commission_assign_access !== true) return;
            openCommissionPlanEditor(null, { returnToContact: true, companyId: form.elements.company_id.value, creationOnly: !can('contacts.read') })
                .catch((error) => setFormError('contact-form-error', error.message));
        });
        $('#contact-form').addEventListener('submit', saveContact);
        $('#contact-chatter-send').addEventListener('click', () => showContactMessageComposer('message'));
        $('#contact-chatter-note').addEventListener('click', () => showContactMessageComposer('note'));
        $('#contact-chatter-activity').addEventListener('click', () => { $('#contact-message-composer').hidden = true; $('#contact-activity-composer').hidden = false; showMessage($('#contact-activity-error'), ''); $('#contact-activity-summary').focus(); });
        $('#contact-message-save').addEventListener('click', saveContactMessage);
        $('#contact-activity-save').addEventListener('click', saveContactActivity);
        $('#contact-chatter-more').addEventListener('click', async (event) => { const button = event.currentTarget; button.disabled = true; try { await loadContactChatter(state.currentContact, state.contactChatter.companyId, true); } catch (error) { button.disabled = false; showMessage($('#contact-chatter-error'), error.message, true); } });
        $$('[data-chatter-cancel]').forEach((button) => button.addEventListener('click', () => { if (button.dataset.chatterCancel === 'message') $('#contact-message-composer').hidden = true; else $('#contact-activity-composer').hidden = true; }));
        $('#contact-form').elements.contact_type.addEventListener('change', updateUboControls);
        $('#company-form').addEventListener('submit', saveCompany);
        $('#document-prefix-company').addEventListener('change', () => loadDocumentPrefixes($('#document-prefix-company').value).catch((error) => showMessage($('#document-prefix-message'), error.message, true)));
        $('#document-prefix-form').addEventListener('submit', saveDocumentPrefixes);
        $('#branch-form').addEventListener('submit', saveBranch);
        $('#company-logo-file').addEventListener('change', () => previewSelectedLogo('company', $('#company-logo-file')));
        $('#branch-logo-file').addEventListener('change', () => previewSelectedLogo('branch', $('#branch-logo-file')));
        $('#company-logo-remove').addEventListener('click', () => removeCompanyLogo('company'));
        $('#branch-logo-remove').addEventListener('click', () => removeCompanyLogo('branch'));
        $('#user-access-form').addEventListener('submit', saveUserProfile);
        $('#user-default-company').addEventListener('change', () => loadUserWarehouseOptions($('#user-default-company').value, '').catch((error) => { const help = $('#user-warehouse-help'); if (help) help.textContent = error.message; }));
        $('#password-change-form').addEventListener('submit', saveOwnPassword);
        $('#user-change-password').addEventListener('click', openPasswordChange);
        $('#user-sessions-refresh').addEventListener('click', loadOwnSessions);
        $('#approval-policy-company').addEventListener('change',()=>loadApprovalPolicy().catch((error)=>showMessage($('#approval-policy-message'),error.message,true)));
        $('#approval-policy-type').addEventListener('change',()=>loadApprovalPolicy().catch((error)=>showMessage($('#approval-policy-message'),error.message,true)));
        $('#approval-add-step').addEventListener('click',()=>appendApprovalPolicyStep());
        $('#approval-policy-save').addEventListener('click',saveApprovalPolicy);
        $('#refresh-approvals').addEventListener('click',()=>loadApprovalInbox().catch((error)=>showMessage($('#approvals-message'),error.message,true)));
        $('#new-user').addEventListener('click', () => openNewUserProfile());
        $('#user-search').addEventListener('input', renderUsers);
        $('#refresh-users').addEventListener('click', () => loadUsers().catch((error) => showMessage($('#users-message'), error.message, true)));
        $$('input[name="access_template"]',$('#user-access-form')).forEach((radio)=>radio.addEventListener('change',()=>{if(!state.currentUserProfile)return;state.currentUserProfile.selectedPermissions=permissionTemplateKeys(radio.value);syncUserPermissionCheckboxes();}));
        $('#add-address').addEventListener('click', addAddress);
        $('#add-bank-account').addEventListener('click', addBankAccount);
        $('#add-ubo-owner').addEventListener('click', addUboOwner);
        $('#add-branch').addEventListener('click', () => openBranch());
        $('#add-child-branch').addEventListener('click', () => {
            const parentId = Number(state.currentBranch?.id || 0);
            if (!parentId) return;
            state.branchReturnParentId = parentId;
            openBranch(null, parentId);
        });
        $('#company-contact-button').addEventListener('click', () => {
            const contactId = Number(state.currentCompany?.contact_id || 0);
            if (contactId) { $('#company-dialog').close(); openContact(contactId); }
        });
        $$('.tab-button').forEach((button) => button.addEventListener('click', () => { switchTabs(button.closest('dialog'), button.dataset.tab); if (button.dataset.tab === 'user-security') { loadOwnSessions(); loadTwoFactorStatus(); loadOwnPasskeys(); } }));
        $$('[data-close]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.close).close()));
        $('#login-form').addEventListener('submit', async (event) => {
            event.preventDefault(); const form = event.currentTarget; const data = new FormData(form);
            setFormError('login-error');
            try {
                let result;
                if(state.loginTwoFactorPending){result=await api('/api/v1/auth/two-factor',{method:'POST',body:JSON.stringify({code:data.get('two_factor_code')})});}
                else{result=await api('/api/v1/auth/login',{method:'POST',body:JSON.stringify({login:data.get('login'),password:data.get('password')})});if(result.requires_two_factor){state.loginTwoFactorPending=true;$('#login-user-field').hidden=true;$('#login-password-field').hidden=true;$('#login-two-factor-field').hidden=false;$('#login-passkey').hidden=true;$('#login-submit').textContent='Verify code';$('#login-two-factor-cancel').hidden=false;form.elements.two_factor_code.required=true;form.elements.password.value='';form.elements.two_factor_code.focus();return;}}
                state.csrf = result.csrf_token || state.csrf; state.loginTwoFactorPending=false;
                await loadSession();
            } catch (error) { setFormError('login-error', error.message); }
        });
        $('#login-two-factor-cancel').addEventListener('click',async()=>{try{const result=await api('/api/v1/auth/two-factor/cancel',{method:'POST',body:JSON.stringify({})});state.csrf=result.csrf_token||state.csrf;}catch(error){setFormError('login-error',error.message);return;}const form=$('#login-form');state.loginTwoFactorPending=false;form.reset();form.elements.two_factor_code.required=false;$('#login-user-field').hidden=false;$('#login-password-field').hidden=false;$('#login-two-factor-field').hidden=true;$('#login-passkey').hidden=!passkeySupported();$('#login-submit').textContent='Sign in';$('#login-two-factor-cancel').hidden=true;setFormError('login-error');form.elements.login.focus();});
        $('#login-passkey').hidden=!passkeySupported();
        $('#login-passkey').addEventListener('click',loginWithPasskey);
        $('#logout-button').addEventListener('click', async () => {
            try { await api('/api/v1/auth/logout', { method: 'POST', body: JSON.stringify({}) }); showSignedOut(); }
            catch (error) { showMessage($('#system-notice'), error.message, true); }
        });
        $('#my-preferences-button').addEventListener('click', () => openMyPreferences().catch((error) => showMessage($('#system-notice'), error.message, true)));
        $('#user-change-password').addEventListener('click',openPasswordChange);
        $('#user-two-factor-button').addEventListener('click',manageTwoFactor);
        $('#user-passkeys-button').addEventListener('click',openPasskeyManager);
        $('#passkey-register-form').addEventListener('submit',registerPasskey);
        $('#passkey-remove-form').addEventListener('submit',removePasskey);
        $('#user-api-keys-button').addEventListener('click',()=>openApiKeys().catch((error)=>setFormError('api-key-error',error.message)));
        $('#api-key-create-form').addEventListener('submit',createApiKey);
        $('#api-key-copy').addEventListener('click',copyApiKey);
        $('#two-factor-begin').addEventListener('click',beginTwoFactorSetup);
        $('#two-factor-confirm').addEventListener('click',confirmTwoFactorSetup);
        $('#two-factor-form').addEventListener('submit',(event)=>event.preventDefault());
        $('#two-factor-finish').addEventListener('click',()=>{$('#two-factor-dialog').close();showMessage($('#users-message'),'Authenticator enrollment saved. Keep the recovery codes private.');});
        $('#two-factor-disable-form').addEventListener('submit',disableTwoFactor);
        $('#password-change-form').addEventListener('submit',saveOwnPassword);
        $('#user-sessions-refresh').addEventListener('click',()=>loadOwnSessions());
        $('#user-session-previous').addEventListener('click',()=>loadOwnSessions(Math.max(0,state.userSessionOffset-state.userSessionLimit)));
        $('#user-session-next').addEventListener('click',()=>loadOwnSessions(state.userSessionOffset+state.userSessionLimit));
        bindManufacturingEvents();
    }
    async function start() {
        bindEvents();
        try {
            const health = await fetch(appUrl('/health'), { credentials: 'same-origin' });
            if (!health.ok) showMessage($('#system-notice'), 'The ERP database is not configured or is currently unavailable. Configure the private environment and database before signing in.', true);
            await loadSession();
            $('#new-contact').disabled = !can('contact_master_data.create');
        } catch (error) {
            showSignedOut(); setFormError('login-error', error.message);
            showMessage($('#system-notice'), error.message, true);
        }
    }
    document.addEventListener('DOMContentLoaded', start);
})();
