/* Shared Odoo-style smart-button panel used by both ERP frontends. */
(function () {
  "use strict";

  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);

  const renderAttributes = (attributes = {}) => Object.entries(attributes)
    .filter(([name, value]) => /^(?:data-[a-z0-9-]+|id|aria-[a-z0-9-]+)$/.test(name) && value !== undefined && value !== null)
    .map(([name, value]) => ` ${name}="${escapeHtml(value)}"`).join("");

  function renderButton(item, menu = false) {
    const classes = ["erp-shared-smart-panel__button", item.className].filter(Boolean).join(" ");
    const label = item.label == null ? "" : `<strong>${escapeHtml(item.label)}</strong>`;
    const value = item.value == null ? "" : `<small>${escapeHtml(item.value)}</small>`;
    const content = item.contentHtml != null ? item.contentHtml : `${label}${value}`;
    const icon = item.iconHtml || (item.icon ? `<span class="erp-shared-smart-panel__icon" aria-hidden="true">${escapeHtml(item.icon)}</span>` : "");
    const role = menu ? ' role="menuitem"' : "";
    const title = item.title ? ` title="${escapeHtml(item.title)}"` : "";
    const aria = item.ariaLabel ? ` aria-label="${escapeHtml(item.ariaLabel)}"` : "";
    const disabled = item.disabled ? " disabled" : "";
    return `<button type="button" class="${escapeHtml(classes)}"${role}${renderAttributes(item.attributes)}${title}${aria}${disabled}>${icon}<span class="erp-shared-smart-panel__copy">${content}</span></button>`;
  }

  function render(options = {}) {
    const items = Array.isArray(options.items) ? options.items : [];
    const moreItems = Array.isArray(options.moreItems) ? options.moreItems : [];
    const rootClass = ["erp-shared-smart-panel", options.className].filter(Boolean).join(" ");
    const itemsClass = ["erp-shared-smart-panel__items", options.itemsClass].filter(Boolean).join(" ");
    const label = options.ariaLabel ? ` aria-label="${escapeHtml(options.ariaLabel)}"` : "";
    const buttons = items.map((item) => renderButton(item)).join("");
    const more = moreItems.length ? `<div class="erp-shared-smart-panel__more ${escapeHtml(options.moreClass || "")}"><button type="button" class="erp-shared-smart-panel__more-button ${escapeHtml(options.moreButtonClass || "")}" data-smart-panel-toggle aria-haspopup="menu" aria-expanded="false">${escapeHtml(options.moreLabel || "More")}<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 6 4.5 4 4.5-4"/></svg></button><div class="erp-shared-smart-panel__menu ${escapeHtml(options.menuClass || "")}" role="menu" hidden>${moreItems.map((item) => renderButton(item, true)).join("")}</div></div>` : "";
    return `<div class="${escapeHtml(rootClass)}"${renderAttributes(options.attributes)}${label}><div class="${escapeHtml(itemsClass)}">${buttons}</div>${more}</div>`;
  }

  function closeMenus(except = null) {
    document.querySelectorAll(".erp-shared-smart-panel__more").forEach((wrapper) => {
      if (except && wrapper === except) return;
      const menu = wrapper.querySelector(".erp-shared-smart-panel__menu");
      const toggle = wrapper.querySelector("[data-smart-panel-toggle]");
      if (menu) menu.hidden = true;
      if (toggle) toggle.setAttribute("aria-expanded", "false");
    });
  }

  document.addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-smart-panel-toggle]");
    if (!toggle) {
      if (event.target.closest(".erp-shared-smart-panel__menu") || !event.target.closest(".erp-shared-smart-panel__more")) closeMenus();
      return;
    }
    const wrapper = toggle.closest(".erp-shared-smart-panel__more");
    const menu = wrapper?.querySelector(".erp-shared-smart-panel__menu");
    if (!wrapper || !menu) return;
    const opening = menu.hidden;
    closeMenus(wrapper);
    menu.hidden = !opening;
    toggle.setAttribute("aria-expanded", String(opening));
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMenus();
  });

  window.ERPSharedSmartPanel = Object.freeze({ render, button: renderButton, closeMenus });
})();
