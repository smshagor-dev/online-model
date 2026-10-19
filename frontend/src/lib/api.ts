export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  method = "GET",
  body?: unknown,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(`/api/backend/${path}`, {
    method,
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      ...(method !== "GET" ? { "Content-Type": "application/json" } : {}),
    },
    ...(method !== "GET" ? { body: JSON.stringify(body ?? {}) } : {}),
    signal,
  });
  const data = response.status === 204 ? null : await response.json();
  if (!response.ok) {
    const validation = data?.errors
      ? Object.values(data.errors).flat()[0]
      : null;
    throw new ApiError(
      String(
        validation ||
          data?.message ||
          "Something went wrong. Please try again.",
      ),
      response.status,
    );
  }
  return data as T;
}
export type User = {
  id: number;
  name: string;
  avatar?: string | null;
  is_admin?: boolean;
  pending_email?: string | null;
  email_verified_at?: string | null;
  email: string;
  two_factor_confirmed_at: string | null;
};
export type Model = {
  id: number;
  name: string;
  family: string;
  license: string;
};
export type Conversation = {
  id: number;
  title: string;
  updated_at: string;
  pinned?: boolean;
  archived_at?: string | null;
  project_id?: number | null;
};
export type Message = {
  id?: number;
  status?: string;
  attachment_ids?: number[];
  sources?: { title: string; url: string; snippet?: string }[];
  feedback?: "up" | "down" | null;
  role: "user" | "assistant";
  content: string;
};
export type Page<T> = { data: T[]; current_page: number; last_page: number };

export async function streamChat(
  body: unknown,
  onEvent: (event: string, data: Record<string, unknown>) => void,
  signal?: AbortSignal,
) {
  const response = await fetch("/api/backend/chat/stream", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
    signal,
  });
  if (!response.ok) {
    const data = await response.json();
    throw new ApiError(
      data.message || "Unable to start chat.",
      response.status,
    );
  }
  if (!response.body) throw new Error("Streaming is unavailable.");
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let doneEvent = false;
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let index;
      while ((index = buffer.indexOf("\n\n")) >= 0) {
        const frame = buffer.slice(0, index);
        buffer = buffer.slice(index + 2);
        const event = frame
          .split("\n")
          .find((line) => line.startsWith("event:"))
          ?.slice(6)
          .trim();
        const payload = frame
          .split("\n")
          .filter((line) => line.startsWith("data:"))
          .map((line) => line.slice(5).trim())
          .join("\n");
        if (event && payload) {
          onEvent(event, JSON.parse(payload));
          if (event === "done") doneEvent = true;
        }
      }
    }
    if (!doneEvent)
      throw new Error(
        "The stream disconnected. Check saved history for the partial response.",
      );
  } finally {
    reader.releaseLock();
  }
}
