export type Preferences = {
  theme: "dark" | "light" | "system";
  accent_color: string;
  text_size: string;
  reduced_motion: boolean;
  send_on_enter: boolean;
  completion_notifications: boolean;
  personalization_enabled: boolean;
  nickname: string | null;
  occupation: string | null;
  about_you: string | null;
  custom_instructions: string | null;
  memory_enabled: boolean;
  response_style: string;
  response_length: string;
  response_language: string;
};
export function applyAppearance(p: Preferences) {
  const root = document.documentElement;
  root.dataset.theme =
    p.theme === "system"
      ? matchMedia("(prefers-color-scheme: light)").matches
        ? "light"
        : "dark"
      : p.theme;
  root.dataset.accent = p.accent_color || "default";
  root.dataset.textSize = p.text_size || "default";
  root.dataset.reducedMotion = String(p.reduced_motion);
}
