(() => {
  const trigger = document.querySelector("[data-open-sidebar]");
  const overlay = document.querySelector("[data-close-sidebar]");
  const close = (restoreFocus = false) => {
    document.body.classList.remove("sidebar-open");
    trigger?.setAttribute("aria-expanded", "false");
    if (restoreFocus) trigger?.focus();
  };
  trigger?.addEventListener("click", () => {
    const open = document.body.classList.toggle("sidebar-open");
    trigger.setAttribute("aria-expanded", String(open));
  });
  overlay?.addEventListener("click", () => close(true));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && document.body.classList.contains("sidebar-open")) close(true);
  });
})();
