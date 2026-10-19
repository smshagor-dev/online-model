"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ArrowUp,
  BookOpen,
  Check,
  ChevronDown,
  Code2,
  Copy,
  Lightbulb,
  LoaderCircle,
  LogIn,
  LogOut,
  Menu,
  MessageCircle,
  MoreHorizontal,
  PanelLeftClose,
  PenLine,
  Search,
  Settings,
  Sparkles,
  SquarePen,
  Trash2,
  X,
} from "lucide-react";
import dynamic from "next/dynamic";
import Dialog from "./dialog";
const AuthDialog = dynamic(() => import("./auth-dialog"));
const AccountDialog = dynamic(() => import("./account-dialog"));
const ResponseMarkdown = dynamic(() => import("./response-markdown"));
import {
  api,
  ApiError,
  type Conversation,
  type Message,
  type Model,
  type Page,
  type User,
} from "@/lib/api";

const suggestions = [
  {
    icon: Code2,
    title: "Build something",
    text: "Help me plan a web application",
    color: "blue",
  },
  {
    icon: PenLine,
    title: "Find the right words",
    text: "Help me write a clear, thoughtful email",
    color: "coral",
  },
  {
    icon: BookOpen,
    title: "Learn something new",
    text: "Explain a complex topic in simple terms",
    color: "purple",
  },
  {
    icon: Lightbulb,
    title: "Explore an idea",
    text: "Help me brainstorm a creative project",
    color: "amber",
  },
];
export default function ChatWorkspace() {
  const [user, setUser] = useState<User | null>(null);
  const [initializing, setInitializing] = useState(true);
  const [models, setModels] = useState<Model[]>([]);
  const [modelId, setModelId] = useState<number | null>(null);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyLastPage, setHistoryLastPage] = useState(1);
  const [messages, setMessages] = useState<Message[]>([]);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [messagePage, setMessagePage] = useState(1);
  const [draft, setDraft] = useState("");
  const [search, setSearch] = useState("");
  const [searchOpen, setSearchOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [authOpen, setAuthOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [modelOpen, setModelOpen] = useState(false);
  const [accountMenu, setAccountMenu] = useState(false);
  const [busy, setBusy] = useState(false);
  const [loadingChat, setLoadingChat] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [error, setError] = useState("");
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const skipScroll = useRef(false);
  const [copied, setCopied] = useState<number | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const chatRequest = useRef<AbortController | null>(null);
  const historyRequest = useRef<AbortController | null>(null);
  const modelMenuRef = useRef<HTMLDivElement>(null);
  const accountMenuRef = useRef<HTMLDivElement>(null);

  const clearSession = useCallback(() => {
    chatRequest.current?.abort();
    historyRequest.current?.abort();
    setUser(null);
    setModels([]);
    setModelId(null);
    setConversations([]);
    setMessages([]);
    setConversationId(null);
    setAccountOpen(false);
    setAccountMenu(false);
    setDraft("");
  }, []);
  const report = useCallback(
    (e: unknown) => {
      if (e instanceof Error && e.name === "AbortError") return;
      if (e instanceof ApiError && e.status === 401) clearSession();
      setError(e instanceof Error ? e.message : "Something went wrong.");
    },
    [clearSession],
  );
  const refreshHistory = useCallback(async (page = 1) => {
    historyRequest.current?.abort();
    const controller = new AbortController();
    historyRequest.current = controller;
    const result = await api<Page<Conversation>>(
      `conversations?page=${page}`,
      "GET",
      undefined,
      controller.signal,
    );
    setConversations((previous) =>
      page === 1
        ? result.data
        : [
            ...previous,
            ...result.data.filter(
              (item) => !previous.some((old) => old.id === item.id),
            ),
          ],
    );
    setHistoryPage(result.current_page);
    setHistoryLastPage(result.last_page);
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    api<User>("me", "GET", undefined, controller.signal)
      .then(setUser)
      .catch((e) => {
        if (!(e instanceof ApiError && e.status === 401)) report(e);
      })
      .finally(() => setInitializing(false));
    return () => controller.abort();
  }, [report]);
  useEffect(() => {
    if (!user) return;
    const controller = new AbortController();
    api<Model[]>("models", "GET", undefined, controller.signal)
      .then((result) => {
        setModels(result);
        setModelId((previous) =>
          result.some((model) => model.id === previous)
            ? previous
            : (result[0]?.id ?? null),
        );
      })
      .catch(report);
    void refreshHistory().catch(report);
    return () => {
      controller.abort();
      historyRequest.current?.abort();
    };
  }, [user?.id, refreshHistory, report]); // Reload data only when the signed-in identity changes.
  useEffect(() => {
    const element = inputRef.current;
    if (element) {
      element.style.height = "auto";
      element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
    }
  }, [draft]);
  useEffect(() => {
    if (skipScroll.current) {
      skipScroll.current = false;
      return;
    }
    if (!loadingChat)
      scrollRef.current?.scrollTo({
        top: scrollRef.current.scrollHeight,
        behavior: "smooth",
      });
  }, [messages.length, busy, loadingChat]);
  useEffect(() => {
    function dismiss(event: MouseEvent) {
      if (!modelMenuRef.current?.contains(event.target as Node))
        setModelOpen(false);
      if (!accountMenuRef.current?.contains(event.target as Node))
        setAccountMenu(false);
    }
    document.addEventListener("mousedown", dismiss);
    return () => document.removeEventListener("mousedown", dismiss);
  }, []);
  useEffect(
    () => () => {
      chatRequest.current?.abort();
      historyRequest.current?.abort();
    },
    [],
  );

  useEffect(() => {
    function keyboard(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setModelOpen(false);
        setAccountMenu(false);
        setSidebarOpen(false);
      }
      if (
        (event.ctrlKey || event.metaKey) &&
        event.key.toLowerCase() === "k" &&
        !busy
      ) {
        event.preventDefault();
        chatRequest.current?.abort();
        setLoadingChat(false);
        setConversationId(null);
        setMessages([]);
        setDraft("");
        setError("");
        setSidebarOpen(false);
        inputRef.current?.focus();
      }
    }
    document.addEventListener("keydown", keyboard);
    return () => document.removeEventListener("keydown", keyboard);
  }, [busy]);
  const scrollBottom = useCallback(() => {
    scrollRef.current?.scrollTo({
      top: scrollRef.current.scrollHeight,
      behavior: "smooth",
    });
  }, []);
  const selectedModel = models.find((model) => model.id === modelId);
  const filteredHistory = conversations.filter((item) =>
    item.title.toLowerCase().includes(search.toLowerCase()),
  );
  function newChat() {
    if (busy) return;
    chatRequest.current?.abort();
    setLoadingChat(false);
    setConversationId(null);
    setMessages([]);
    setMessagePage(1);
    setDraft("");
    setError("");
    setSidebarOpen(false);
    inputRef.current?.focus();
  }
  async function openChat(id: number) {
    if (busy || deleteId) return;
    chatRequest.current?.abort();
    const controller = new AbortController();
    chatRequest.current = controller;
    setLoadingChat(true);
    setError("");
    setSidebarOpen(false);
    try {
      let result = await api<{
        conversation: Conversation;
        messages: Page<Message>;
      }>(`conversations/${id}`, "GET", undefined, controller.signal);
      if (result.messages.last_page > 1)
        result = await api(
          `conversations/${id}?page=${result.messages.last_page}`,
          "GET",
          undefined,
          controller.signal,
        );
      if (controller.signal.aborted) return;
      setConversationId(id);
      setMessages(result.messages.data);
      setMessagePage(result.messages.current_page);
      setDraft("");
    } catch (e) {
      report(e);
    } finally {
      if (!controller.signal.aborted) setLoadingChat(false);
    }
  }
  async function olderMessages() {
    if (!conversationId || loadingChat || busy) return;
    chatRequest.current?.abort();
    const controller = new AbortController();
    chatRequest.current = controller;
    setLoadingChat(true);
    try {
      const result = await api<{ messages: Page<Message> }>(
        `conversations/${conversationId}?page=${messagePage - 1}`,
        "GET",
        undefined,
        controller.signal,
      );
      if (controller.signal.aborted) return;
      skipScroll.current = true;
      setMessages((previous) => [...result.messages.data, ...previous]);
      setMessagePage(result.messages.current_page);
    } catch (e) {
      report(e);
    } finally {
      if (!controller.signal.aborted) setLoadingChat(false);
    }
  }
  async function send(event?: React.FormEvent) {
    event?.preventDefault();
    if (!draft.trim() || busy || loadingChat || initializing) return;
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (!modelId) {
      setError(
        "No model is available yet. Your administrator needs to download and enable one.",
      );
      return;
    }
    const text = draft.trim();
    const oldMessages = messages;
    setError("");
    setBusy(true);
    setDraft("");
    setMessages([...messages, { role: "user", content: text }]);
    try {
      const result = await api<{ conversation_id: number; message: Message }>(
        "chat",
        "POST",
        {
          model_id: modelId,
          message: text,
          ...(conversationId ? { conversation_id: conversationId } : {}),
        },
      );
      setConversationId(result.conversation_id);
      setMessages((previous) => [...previous, result.message]);
      void refreshHistory().catch(report);
    } catch (e) {
      setMessages(oldMessages);
      setDraft(text);
      report(e);
    } finally {
      setBusy(false);
      inputRef.current?.focus();
    }
  }
  async function deleteConversation(id: number) {
    setDeleteBusy(true);
    try {
      await api(`conversations/${id}`, "DELETE");
      setConversations((previous) => previous.filter((item) => item.id !== id));
      if (conversationId === id) newChat();
      setDeleteId(null);
    } catch (e) {
      report(e);
    } finally {
      setDeleteBusy(false);
    }
  }
  async function logout() {
    try {
      await api("logout", "POST");
      clearSession();
      setDraft("");
      setError("");
    } catch (e) {
      report(e);
    }
  }
  async function copy(content: string, index: number) {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(index);
      setTimeout(() => setCopied(null), 1800);
    } catch {
      setError("Clipboard unavailable. Select the text to copy it.");
    }
  }

  return (
    <div className={`workspace ${sidebarCollapsed ? "sidebar-collapsed" : ""}`}>
      {sidebarOpen && (
        <button
          className="sidebar-overlay"
          aria-label="Close sidebar"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside
        className={`sidebar ${sidebarOpen ? "mobile-open" : ""}`}
        aria-label="Chat history"
      >
        <div className="sidebar-top">
          <button
            className="brand-symbol"
            aria-label="New chat"
            onClick={newChat}
            disabled={busy}
          >
            <Sparkles size={23} strokeWidth={1.7} />
          </button>
          <button
            className="icon-button sidebar-toggle"
            aria-label="Collapse sidebar"
            onClick={() => {
              setSidebarCollapsed(true);
              setSidebarOpen(false);
            }}
          >
            <PanelLeftClose size={20} />
          </button>
        </div>
        <button className="sidebar-action" onClick={newChat} disabled={busy}>
          <SquarePen size={19} />
          <span>New chat</span>
          <small>⌘ K</small>
        </button>
        <button
          className="sidebar-action"
          onClick={() => {
            setSearchOpen(!searchOpen);
          }}
        >
          <Search size={19} />
          <span>Search chats</span>
        </button>
        {searchOpen && (
          <div className="history-search">
            <Search size={15} />
            <input
              aria-label="Search saved chats"
              placeholder="Search loaded chats"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              autoFocus
            />
            <button
              className="icon-button"
              aria-label="Clear search"
              onClick={() => setSearch("")}
            >
              <X size={14} />
            </button>
          </div>
        )}
        <div className="history-list">
          <span className="section-label">Your chats</span>
          {initializing ? (
            <p className="sidebar-empty">Loading your workspace…</p>
          ) : !user ? (
            <div className="sidebar-empty">
              <MessageCircle size={23} />
              <p>
                Your next great idea
                <br />
                starts with a conversation.
              </p>
              <button className="text-button" onClick={() => setAuthOpen(true)}>
                Sign in to save your chats
              </button>
            </div>
          ) : filteredHistory.length === 0 ? (
            <p className="sidebar-empty">
              {search
                ? "No matching conversations."
                : "Your conversations will appear here."}
            </p>
          ) : (
            filteredHistory.map((chat) => (
              <div
                className={`history-item ${conversationId === chat.id ? "active" : ""}`}
                key={chat.id}
              >
                <button
                  className="history-title"
                  disabled={busy || loadingChat}
                  onClick={() => void openChat(chat.id)}
                  title={chat.title}
                >
                  {chat.title}
                </button>
                <button
                  className="history-delete icon-button"
                  disabled={busy}
                  onClick={() => setDeleteId(chat.id)}
                  aria-label={`Delete ${chat.title}`}
                >
                  <Trash2 size={14} />
                </button>
              </div>
            ))
          )}
          {user && historyPage < historyLastPage && (
            <button
              className="history-more"
              disabled={loadingHistory}
              onClick={async () => {
                setLoadingHistory(true);
                try {
                  await refreshHistory(historyPage + 1);
                } catch (e) {
                  report(e);
                } finally {
                  setLoadingHistory(false);
                }
              }}
            >
              {loadingHistory ? "Loading…" : "Load more chats"}
            </button>
          )}
        </div>
        <div className="sidebar-footer" ref={accountMenuRef}>
          {user ? (
            <>
              <button
                className="profile-button"
                onClick={() => setAccountMenu(!accountMenu)}
                aria-expanded={accountMenu}
              >
                <span className="avatar">
                  {user.name.charAt(0).toUpperCase()}
                </span>
                <span>
                  <strong>{user.name}</strong>
                  <small>Personal workspace</small>
                </span>
                <MoreHorizontal size={18} />
              </button>
              {accountMenu && (
                <div className="account-menu popover">
                  <button
                    onClick={() => {
                      setAccountOpen(true);
                      setAccountMenu(false);
                    }}
                  >
                    <Settings size={17} /> Settings
                  </button>
                  <button disabled={busy} onClick={() => void logout()}>
                    <LogOut size={17} /> Sign out
                  </button>
                </div>
              )}
            </>
          ) : (
            <button
              className="profile-button"
              onClick={() => setAuthOpen(true)}
              disabled={initializing}
            >
              <span className="avatar guest">
                <LogIn size={18} />
              </span>
              <span>
                <strong>Make it your space</strong>
                <small>Sign in or create an account</small>
              </span>
            </button>
          )}
        </div>
      </aside>
      <main className="main-panel">
        <header className="topbar">
          <div className="topbar-left">
            <button
              className="icon-button mobile-menu"
              aria-label="Open sidebar"
              onClick={() => setSidebarOpen(true)}
            >
              <Menu size={21} />
            </button>
            {sidebarCollapsed && (
              <button
                className="icon-button desktop-expand"
                aria-label="Expand sidebar"
                onClick={() => setSidebarCollapsed(false)}
              >
                <Menu size={21} />
              </button>
            )}
            <div className="model-menu-wrapper" ref={modelMenuRef}>
              <button
                className="model-picker"
                onClick={() => setModelOpen(!modelOpen)}
                aria-expanded={modelOpen}
                disabled={busy}
              >
                <span>Online Model</span>
                <ChevronDown size={17} />
              </button>
              {selectedModel && (
                <span className="active-model-label">{selectedModel.name}</span>
              )}
              {modelOpen && (
                <div className="model-menu popover">
                  <div className="popover-heading">
                    Choose a model<small>Your self-hosted models</small>
                  </div>
                  {!user ? (
                    <button
                      onClick={() => {
                        setAuthOpen(true);
                        setModelOpen(false);
                      }}
                    >
                      <LogIn size={17} /> Sign in to view models
                    </button>
                  ) : models.length ? (
                    models.map((model) => (
                      <button
                        key={model.id}
                        onClick={() => {
                          setModelId(model.id);
                          setModelOpen(false);
                        }}
                      >
                        <span className="model-glyph">
                          <Sparkles size={17} />
                        </span>
                        <span>
                          <strong>{model.name}</strong>
                          <small>{model.family}</small>
                        </span>
                        {modelId === model.id && <Check size={17} />}
                      </button>
                    ))
                  ) : (
                    <p className="muted model-empty">
                      No enabled models. Ask your administrator to add one.
                    </p>
                  )}
                </div>
              )}
            </div>
          </div>
          <div className="topbar-right">
            <span className="private-label">
              <span className="status-dot" />
              Your models. Your space.
            </span>
            {!user && (
              <button
                className="login-pill"
                onClick={() => setAuthOpen(true)}
                disabled={initializing}
              >
                Log in
              </button>
            )}
            {user && (
              <button
                className="icon-button"
                aria-label="Open settings"
                onClick={() => setAccountOpen(true)}
              >
                <Settings size={19} />
              </button>
            )}
          </div>
        </header>
        <div className="chat-scroll" ref={scrollRef}>
          {messages.length === 0 && !loadingChat ? (
            <section className="welcome">
              <div className="welcome-mark">
                <Sparkles size={28} strokeWidth={1.4} />
              </div>
              <p className="eyebrow">A LITTLE CURIOSITY GOES A LONG WAY</p>
              <h1>What’s on your mind?</h1>
              <p className="welcome-subtitle">
                A question, a first draft, a spark of an idea.
                <br className="mobile-break" /> Let’s make something of it.
              </p>
              <div className="suggestions">
                {suggestions.map((item) => (
                  <button
                    key={item.title}
                    onClick={() => {
                      setDraft(item.text);
                      inputRef.current?.focus();
                    }}
                  >
                    <item.icon
                      className={item.color}
                      size={21}
                      strokeWidth={1.6}
                    />
                    <span>{item.title}</span>
                    <small>{item.text}</small>
                  </button>
                ))}
              </div>
            </section>
          ) : (
            <div className="thread">
              {messagePage > 1 && (
                <button
                  className="load-earlier secondary-button"
                  disabled={loadingChat || busy}
                  onClick={() => void olderMessages()}
                >
                  Load earlier messages
                </button>
              )}
              {messages.map((message, index) => (
                <article
                  className={`message ${message.role}`}
                  key={message.id ?? `message-${index}`}
                  aria-label={
                    message.role === "user" ? "Your message" : "Model response"
                  }
                >
                  {message.role === "assistant" && (
                    <span className="assistant-avatar">
                      <Sparkles size={19} />
                    </span>
                  )}
                  <div className="message-body">
                    {message.role === "user" ? (
                      <div className="user-bubble">{message.content}</div>
                    ) : (
                      <>
                        <ResponseMarkdown
                          content={message.content}
                          onRendered={
                            index === messages.length - 1
                              ? scrollBottom
                              : undefined
                          }
                        />
                        <div className="message-actions">
                          <button
                            className="icon-button"
                            onClick={() => void copy(message.content, index)}
                            aria-label="Copy response"
                          >
                            {copied === index ? (
                              <Check size={15} />
                            ) : (
                              <Copy size={15} />
                            )}
                          </button>
                        </div>
                      </>
                    )}
                  </div>
                </article>
              ))}
              {busy && (
                <div className="thinking" role="status">
                  <span className="assistant-avatar">
                    <Sparkles size={19} />
                  </span>
                  <span className="thinking-dots">
                    <i />
                    <i />
                    <i />
                  </span>
                  <span className="sr-only">Waiting for your model…</span>
                </div>
              )}
            </div>
          )}
          {loadingChat && (
            <div className="chat-loading" role="status">
              <LoaderCircle className="spin" size={21} />
              Loading conversation…
            </div>
          )}
        </div>
        <div
          className={`composer-area ${messages.length === 0 ? "empty-composer" : ""}`}
        >
          {error && (
            <div className="chat-error" role="alert">
              <span>{error}</span>
              <button
                className="icon-button"
                aria-label="Dismiss error"
                onClick={() => setError("")}
              >
                <X size={15} />
              </button>
            </div>
          )}
          {!initializing && user && !models.length && (
            <p className="model-warning">
              No models available yet. An administrator can add one from the
              model library.
            </p>
          )}
          <form className="composer" onSubmit={send}>
            <textarea
              ref={inputRef}
              aria-label="Message"
              placeholder="Ask anything"
              value={draft}
              maxLength={16000}
              rows={1}
              disabled={busy || loadingChat}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (
                  e.key === "Enter" &&
                  !e.shiftKey &&
                  !e.nativeEvent.isComposing
                ) {
                  e.preventDefault();
                  void send();
                }
              }}
            />
            <div className="composer-toolbar">
              <span className="composer-hint">
                <Sparkles size={15} />
                {selectedModel?.family || "Your AI, at your pace"}
              </span>
              <div className="composer-right">
                {draft.length > 14000 && (
                  <small>{draft.length.toLocaleString()}/16,000</small>
                )}
                <button
                  className="send-button"
                  type="submit"
                  aria-label="Send message"
                  disabled={
                    !draft.trim() ||
                    busy ||
                    loadingChat ||
                    initializing ||
                    (!!user && !modelId)
                  }
                >
                  {busy ? (
                    <LoaderCircle size={19} className="spin" />
                  ) : (
                    <ArrowUp size={20} strokeWidth={2.5} />
                  )}
                </button>
              </div>
            </div>
          </form>
          <p className="composer-note">
            AI can make mistakes. Check important information.
            <span>Shift + Enter for a new line</span>
          </p>
        </div>
      </main>
      {authOpen && (
        <AuthDialog
          onClose={() => setAuthOpen(false)}
          onLogin={(account) => {
            setUser(account);
            setAuthOpen(false);
            setError("");
          }}
        />
      )}
      {accountOpen && user && (
        <AccountDialog
          user={user}
          onClose={() => setAccountOpen(false)}
          onUpdate={setUser}
          onSignedOut={clearSession}
        />
      )}
      {deleteId && (
        <Dialog
          title="Delete this chat?"
          onClose={() => {
            if (!deleteBusy) setDeleteId(null);
          }}
        >
          <p className="dialog-description">
            This removes the conversation and its messages from your account.
          </p>
          <div className="button-row">
            <button
              className="secondary-button"
              disabled={deleteBusy}
              onClick={() => setDeleteId(null)}
            >
              Cancel
            </button>
            <button
              className="danger-button"
              disabled={deleteBusy}
              onClick={() => void deleteConversation(deleteId)}
            >
              {deleteBusy ? "Deleting…" : "Delete chat"}
            </button>
          </div>
        </Dialog>
      )}
    </div>
  );
}
