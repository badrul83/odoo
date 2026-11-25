(() => {
    'use strict';
    const root = document.getElementById('booking-card');
    const basePath = document.querySelector('meta[name="erp-base-path"]')?.content || '';
    const appUrl = (url) => url.startsWith('/') && !url.startsWith('//') ? `${basePath}${url}` : url;
    const path = basePath && location.pathname.startsWith(`${basePath}/`) ? location.pathname.slice(basePath.length) : location.pathname;
    const cancelMatch = path.match(/^\/book\/cancel\/([a-f0-9]{64})$/);
    const manageMatch = path.match(/^\/book\/manage\/([a-f0-9]{64})$/);
    const typeMatch = path.match(/^\/book\/([a-f0-9]{64})$/);
    let csrf = '';
    let type = null;
    let availableSlots = [];
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]);
    async function request(url, options = {}) {
        const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
        if (options.body !== undefined) { headers.set('Content-Type','application/json'); headers.set('X-CSRF-Token', csrf); }
        const response = await fetch(appUrl(url), { ...options, headers, credentials:'same-origin' }); const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) throw new Error(payload?.error || `Request failed (${response.status}).`);
        if (payload.csrf_token) csrf = payload.csrf_token; return payload;
    }
    function showError(message) { const node = document.getElementById('booking-error'); if (node) node.textContent = message; }
    function dateInputMinimum() { const now = new Date(); return new Date(now.getTime() - now.getTimezoneOffset()*60000).toISOString().slice(0,10); }
    function renderQuestions(questions) {
        const box = document.getElementById('booking-questions'); box.replaceChildren();
        for (const question of questions || []) {
            const label = document.createElement('label'); label.textContent = question.question;
            const input = question.answer_type === 'text' ? document.createElement('textarea') : document.createElement('input');
            if (input.tagName === 'INPUT') input.type = question.answer_type === 'number' ? 'number' : question.answer_type === 'email' ? 'email' : question.answer_type === 'phone' ? 'tel' : 'text';
            input.dataset.questionId = String(question.id); input.required = Boolean(Number(question.is_required)); input.maxLength = 2000; label.append(input); box.append(label);
        }
    }
    async function loadSlots() {
        showError(''); const date = document.getElementById('booking-date').value; if (!date) return;
        const guests = Number(document.getElementById('booking-guests')?.value || 1); const select = document.getElementById('booking-slot'); select.replaceChildren(new Option('Loading available times…',''));
        try {
            const result = await request(`/api/v1/public/appointments/${type.public_token}/slots?date=${encodeURIComponent(date)}&guest_count=${guests}`); availableSlots = result.slots || [];
            const slots = [...new Map(availableSlots.map((slot) => [slot.starts_at_utc, slot])).values()];
            select.replaceChildren(new Option(slots.length ? 'Select a time' : 'No available times',''));
            for (const slot of slots) select.add(new Option(slot.label.replace(/ · .*$/,''), slot.starts_at_utc));
            updateProviders();
        } catch (error) { select.replaceChildren(new Option('Times could not be loaded','')); showError(error.message); }
    }
    function updateProviders() {
        const wrap = document.getElementById('booking-provider-wrap'); if (!wrap) return;
        const visible = type.assignment_method === 'visitor'; wrap.hidden = !visible;
        const select = document.getElementById('booking-provider'); select.required = visible; if (!visible) return;
        const start = document.getElementById('booking-slot').value; const providers = [...new Map(availableSlots.filter((slot) => slot.starts_at_utc === start).map((slot) => [slot.provider_key, slot.provider_name])).entries()];
        select.replaceChildren(new Option('Choose a provider','')); for (const [key, name] of providers) select.add(new Option(name,key));
    }
    async function renderType(token) {
        const result = await request(`/api/v1/public/appointments/${token}`); type = result.type; type.public_token = token;
        const guestField = Number(type.allow_guests) ? `<label>Number of guests<input id="booking-guests" type="number" min="1" max="${Number(type.capacity_per_slot)}" value="1" required></label>` : '';
        const intro = type.intro_message ? `<p>${esc(type.intro_message)}</p>` : '';
        root.innerHTML = `<header class="booking-head"><div class="eyebrow">ONLINE APPOINTMENT</div><h1>${esc(type.name)}</h1>${intro}<p>${Number(type.duration_minutes)} minutes · ${esc(type.timezone)}${type.location ? ` · ${esc(type.location)}` : ''}</p></header><form id="public-booking-form" class="booking-form"><div class="booking-grid"><label>Your name<input name="name" maxlength="190" autocomplete="name" required></label><label>Email<input name="email" type="email" maxlength="190" autocomplete="email" required></label></div><label>Phone <span class="small">Optional</span><input name="phone" maxlength="80" autocomplete="tel"></label><div class="booking-grid"><label>Date<input id="booking-date" type="date" min="${dateInputMinimum()}" required></label>${guestField}</div><label>Available time<select id="booking-slot" name="starts_at_utc" required><option value="">Choose a date first</option></select></label><div id="booking-provider-wrap" hidden><label>Choose a provider<select id="booking-provider" required></select></label></div><div id="booking-questions" class="booking-questions"></div><label>Message <span class="small">Optional</span><textarea name="notes" rows="3" maxlength="2000"></textarea></label><label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><div id="booking-error" class="booking-error" role="alert"></div><div id="booking-message" class="booking-message" role="status"></div><button class="button button-primary" type="submit">Book appointment</button></form>`;
        renderQuestions(result.questions);
        document.getElementById('booking-date').addEventListener('change',loadSlots);
        document.getElementById('booking-guests')?.addEventListener('change',loadSlots);
        document.getElementById('booking-slot').addEventListener('change',updateProviders);
        document.getElementById('public-booking-form').addEventListener('submit',submitBooking);
        updateProviders();
        await loadSlots();
    }
    async function submitBooking(event) {
        event.preventDefault(); showError(''); const form = event.currentTarget; if (!form.reportValidity()) return;
        const button = form.querySelector('button[type="submit"]'); button.disabled = true; button.textContent = 'Booking…';
        const payload = Object.fromEntries(new FormData(form).entries()); payload.guest_count = Number(document.getElementById('booking-guests')?.value || 1); if (type.assignment_method === 'visitor') payload.provider_key = document.getElementById('booking-provider').value;
        payload.answers = [...document.querySelectorAll('#booking-questions [data-question-id]')].map((input) => ({ question_id:Number(input.dataset.questionId), answer:input.value }));
        try {
            const result = await request(`/api/v1/public/appointments/${type.public_token}/book`, { method:'POST', body:JSON.stringify(payload) }); const booking = result.booking;
            root.innerHTML = `<header class="booking-head"><div class="eyebrow">BOOKING CONFIRMED</div><h1>${esc(type.name)}</h1><p>${esc(type.confirmation_message || 'Your appointment has been booked.')}</p></header><div class="booking-form"><div class="booking-message">${esc(booking.booking_name)} · ${esc(new Intl.DateTimeFormat(undefined,{dateStyle:'full',timeStyle:'short',timeZone:booking.timezone}).format(new Date(`${booking.starts_at_utc.replace(' ','T')}Z`)))} (${esc(booking.timezone)})</div><p class="small">A Contact record has been created or matched for this booking.</p><a class="button button-primary" href="${esc(appUrl(booking.manage_url))}">Manage or reschedule</a><a class="button" href="${esc(appUrl(booking.cancel_url))}">Cancel this appointment</a></div>`;
        } catch (error) { showError(error.message); button.disabled=false;button.textContent='Book appointment'; }
    }
    async function renderCancellation(token) {
        const result = await request(`/api/v1/public/appointments/cancel/${token}`); const booking = result.booking;
        root.innerHTML = `<header class="booking-head"><div class="eyebrow">CANCEL APPOINTMENT</div><h1>${esc(booking.appointment_type)}</h1><p>${esc(booking.booking_name)} · ${esc(new Intl.DateTimeFormat(undefined,{dateStyle:'full',timeStyle:'short',timeZone:booking.timezone}).format(new Date(`${booking.starts_at_utc.replace(' ','T')}Z`)))} (${esc(booking.timezone)})</p></header><form id="cancel-form" class="booking-form"><label>Reason <span class="small">Optional</span><textarea name="reason" rows="3" maxlength="500"></textarea></label><div id="booking-error" class="booking-error" role="alert"></div><div id="booking-message" class="booking-message" role="status"></div>${booking.can_cancel ? '<button class="button button-primary" type="submit">Confirm cancellation</button>' : '<p class="booking-error">The cancellation window has closed. Please contact the company to change this booking.</p>'}</form>`;
        document.getElementById('cancel-form').addEventListener('submit',async(event)=>{event.preventDefault();const button=event.currentTarget.querySelector('button[type="submit"]');if(!button)return;button.disabled=true;try{const body=Object.fromEntries(new FormData(event.currentTarget).entries());const canceled=await request(`/api/v1/public/appointments/cancel/${token}`,{method:'POST',body:JSON.stringify(body)});event.currentTarget.innerHTML=`<div class="booking-message">${esc(canceled.message)}</div>`;}catch(error){showError(error.message);button.disabled=false;}});
    }
    function dateForZone(value, zone) {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(`${value.replace(' ','T')}Z`));
        const part = (type) => parts.find((item) => item.type === type)?.value || '';
        return `${part('year')}-${part('month')}-${part('day')}`;
    }
    function todayForZone(zone) {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
        const part = (type) => parts.find((item) => item.type === type)?.value || '';
        return `${part('year')}-${part('month')}-${part('day')}`;
    }
    async function renderManage(token) {
        const result = await request(`/api/v1/public/appointments/manage/${token}`); const booking = result.booking;
        const localTime = (value) => new Intl.DateTimeFormat(undefined, { dateStyle: 'full', timeStyle: 'short', timeZone: booking.timezone }).format(new Date(`${value.replace(' ','T')}Z`));
        root.innerHTML = `<header class="booking-head"><div class="eyebrow">MANAGE APPOINTMENT</div><h1>${esc(booking.appointment_type)}</h1><p>${esc(booking.booking_name)} · ${esc(localTime(booking.starts_at_utc))} (${esc(booking.timezone)})</p></header><div class="booking-form"><div id="manage-message" class="booking-message" role="status"></div>${booking.can_reschedule ? `<form id="reschedule-form" class="booking-form manage-form"><p class="small">Choose another available time. Your ${Number(booking.guest_count)} guest(s) and Contact link will be kept.</p><label>Date<input id="manage-date" type="date" required></label><label>Available time<select id="manage-slot" required><option value="">Choose a date first</option></select></label><div id="manage-provider-wrap" hidden><label>Provider<select id="manage-provider" required></select></label></div><div id="booking-error" class="booking-error" role="alert"></div><button class="button button-primary" type="submit">Reschedule appointment</button></form>` : '<p class="booking-error">The rescheduling window has closed or this appointment is no longer bookable. Please contact the company.</p>'}${booking.can_cancel ? `<form id="manage-cancel-form"><label>Cancellation reason <span class="small">Optional</span><textarea name="reason" rows="2" maxlength="500"></textarea></label><button class="button" type="submit">Cancel appointment</button></form>` : '<p class="small">The cancellation window has closed.</p>'}</div>`;
        if (booking.can_reschedule) {
            const dateInput = document.getElementById('manage-date'); dateInput.min = todayForZone(booking.timezone); dateInput.value = dateForZone(booking.starts_at_utc, booking.timezone);
            let slots = [];
            const providerWrap = document.getElementById('manage-provider-wrap');
            const updateProviders = () => {
                providerWrap.hidden = false;
                const select = document.getElementById('manage-provider'); select.required = true; select.replaceChildren(new Option('Choose a provider',''));
                for (const row of slots.filter((item) => item.starts_at_utc === document.getElementById('manage-slot').value)) select.add(new Option(row.provider_name, row.provider_key));
            };
            const loadSlots = async () => {
                showError(''); const select = document.getElementById('manage-slot'); select.replaceChildren(new Option('Loading available times…',''));
                try {
                    const response = await request(`/api/v1/public/appointments/manage/${token}/slots?date=${encodeURIComponent(dateInput.value)}`); slots = response.slots || [];
                    const options = [...new Map(slots.map((item) => [item.starts_at_utc, item])).values()]; select.replaceChildren(new Option(options.length ? 'Select a time' : 'No available times',''));
                    for (const item of options) select.add(new Option(item.label.replace(/ · .*$/,''), item.starts_at_utc));
                    if (slots.some((item) => item.provider_key)) updateProviders(); else { providerWrap.hidden = true; document.getElementById('manage-provider').required = false; }
                } catch (error) { select.replaceChildren(new Option('Times could not be loaded','')); showError(error.message); }
            };
            dateInput.addEventListener('change', loadSlots); document.getElementById('manage-slot').addEventListener('change', updateProviders);
            document.getElementById('reschedule-form').addEventListener('submit', async (event) => {
                event.preventDefault(); showError(''); const button = event.currentTarget.querySelector('button[type="submit"]'); button.disabled = true;
                try {
                    const payload = { starts_at_utc: document.getElementById('manage-slot').value };
                    if (!providerWrap.hidden) payload.provider_key = document.getElementById('manage-provider').value;
                    const saved = await request(`/api/v1/public/appointments/manage/${token}`, { method: 'POST', body: JSON.stringify(payload) });
                    const updated = saved.booking; root.querySelector('.booking-head p').textContent = `${updated.booking_name} · ${localTime(updated.starts_at_utc)} (${updated.timezone})`;
                    document.getElementById('manage-message').textContent = 'Your appointment has been rescheduled. The booking is still linked to your Contact record.';
                    dateInput.value = dateForZone(updated.starts_at_utc, updated.timezone); await loadSlots();
                } catch (error) { showError(error.message); } finally { button.disabled = false; }
            });
            await loadSlots();
        }
        document.getElementById('manage-cancel-form')?.addEventListener('submit', async (event) => {
            event.preventDefault(); if (!window.confirm('Cancel this appointment?')) return; const button = event.currentTarget.querySelector('button[type="submit"]'); button.disabled = true;
            try { const body = Object.fromEntries(new FormData(event.currentTarget).entries()); const canceled = await request(`/api/v1/public/appointments/cancel/${token}`, { method: 'POST', body: JSON.stringify(body) }); root.querySelector('.booking-form').replaceChildren(Object.assign(document.createElement('div'), { className: 'booking-message', textContent: canceled.message })); }
            catch (error) { showError(error.message); button.disabled = false; }
        });
    }
    (async () => { try { if (cancelMatch) await renderCancellation(cancelMatch[1]); else if (manageMatch) await renderManage(manageMatch[1]); else if (typeMatch) await renderType(typeMatch[1]); else throw new Error('This appointment link is invalid.'); } catch (error) { root.innerHTML = `<div class="booking-form"><div class="booking-error">${esc(error.message)}</div></div>`; } })();
})();
