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
  email: string;
  two_factor_confirmed_at: string | null;
};
export type Model = {
  id: number;
  name: string;
  family: string;
  license: string;
};
export type Conversation = { id: number; title: string; updated_at: string };
export type Message = {
  id?: number;
  role: "user" | "assistant";
  content: string;
};
export type Page<T> = { data: T[]; current_page: number; last_page: number };
