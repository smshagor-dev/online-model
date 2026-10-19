"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ArrowUp,
  Paperclip,
  FolderOpen,
  Archive,
  Pin,
  Square,
  RotateCcw,
  Pencil,
  ThumbsUp,
  ThumbsDown,
  Volume2,
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
import Image from "next/image";
import Dialog from "./dialog";
import { applyAppearance, type Preferences } from "@/lib/settings";
const ToolsWorkbench = dynamic(() => import("./tools-workbench"));
const FilePreview = dynamic(() => import("./file-preview"));
const InlineMedia = dynamic(() => import("./inline-media"));
const AuthDialog = dynamic(() => import("./auth-dialog"));
const AccountDialog = dynamic(() => import("./account-dialog"));
const MediaDialog = dynamic(() => import("./media-dialog"));
const CanvasDialog = dynamic(() => import("./canvas-dialog"));
const WorkspaceDialog = dynamic(() => import("./workspace-dialog"));
const ConversationDialog = dynamic(() => import("./conversation-dialog"));
const ResponseMarkdown = dynamic(() => import("./response-markdown"));
import {
  api,
  streamChat,
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
  const [settingsRevision, setSettingsRevision] = useState(0);
  const [preferences, setPreferences] = useState<Preferences | null>(null);
  const [toolsOpen, setToolsOpen] = useState<string | null>(null);
  const [filePreview, setFilePreview] = useState<File | null>(null);
  const [inlineMedia, setInlineMedia] = useState<number[]>([]);
  const [assistant, setAssistant] = useState<{
    id: number;
    name: string;
    model_id: number | null;
  } | null>(null);
  const [branches, setBranches] = useState<
    { id: number; title: string }[] | null
  >(null);
  const [mediaMode, setMediaMode] = useState("chat");
  const [mediaKinds, setMediaKinds] = useState<string[]>([]);
  const [searchAvailable, setSearchAvailable] = useState(false);
  const [webSearch, setWebSearch] = useState(false);
  const [canvasInitial, setCanvasInitial] = useState<string | null>(null);
  const [mediaOpen, setMediaOpen] = useState(false);
  const [workspaceOpen, setWorkspaceOpen] = useState(false);
  const [toolsChat, setToolsChat] = useState<Conversation | null>(null);
  const [projects, setProjects] = useState<{ id: number; name: string }[]>([]);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [archived, setArchived] = useState(false);
  const [temporary, setTemporary] = useState(false);
  const [attachments, setAttachments] = useState<
    { id: number; name: string }[]
  >([]);
  const [uploading, setUploading] = useState(false);
  const [readAloud, setReadAloud] = useState(false);
  const filtersRef = useRef({
    search: "",
    projectId: null as number | null,
    archived: false,
  });
  const generationRef = useRef<AbortController | null>(null);
  const activeStream = useRef<{
    conversation_id: number | null;
    message_id: number | null;
  } | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [user, setUser] = useState<User | null>(null);
  const identityRef = useRef<number | null>(null);
  identityRef.current = user?.id ?? null;
  const uploadRequest = useRef<AbortController | null>(null);
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
    generationRef.current?.abort();
    uploadRequest.current?.abort();
    setWorkspaceOpen(false);
    setMediaOpen(false);
    setToolsOpen(null);
    setFilePreview(null);
    setInlineMedia([]);
    setAssistant(null);
    setBranches(null);
    setCanvasInitial(null);
    setToolsChat(null);
    setUser(null);
    setPreferences(null);
    delete document.documentElement.dataset.accent;
    delete document.documentElement.dataset.textSize;
    delete document.documentElement.dataset.reducedMotion;
    delete document.documentElement.dataset.theme;
    setModels([]);
    setModelId(null);
    setConversations([]);
    setMessages([]);
    setConversationId(null);
    setAccountOpen(false);
    setAccountMenu(false);
    setDraft("");
    setTemporary(false);
    setAttachments([]);
    setProjects([]);
    setProjectId(null);
    setArchived(false);
    setSearch("");
    setWebSearch(false);
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
      `conversations?page=${page}&q=${encodeURIComponent(filtersRef.current.search)}&archived=${filtersRef.current.archived ? 1 : 0}${filtersRef.current.projectId ? `&project_id=${filtersRef.current.projectId}` : ""}`,
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
    void api<{ web_search: boolean; media: string[] }>(
      "capabilities",
      "GET",
      undefined,
      controller.signal,
    )
      .then((c) => {
        setSearchAvailable(c.web_search);
        setMediaKinds(c.media || []);
      })
      .catch(report);
    void api<{ id: number; name: string }[]>(
      "projects",
      "GET",
      undefined,
      controller.signal,
    )
      .then(setProjects)
      .catch(report);
    return () => {
      controller.abort();
      historyRequest.current?.abort();
    };
  }, [user?.id, refreshHistory, report]); // Reload data only when the signed-in identity changes.
  useEffect(() => {
    if (!user) return;
    const controller = new AbortController();
    const media = matchMedia("(prefers-color-scheme: light)");
    let saved: Preferences | null = null;
    const change = () => {
      if (saved) applyAppearance(saved);
    };
    api<Preferences>("preferences", "GET", undefined, controller.signal)
      .then((p) => {
        saved = p;
        setPreferences(p);
        change();
      })
      .catch(report);
    media.addEventListener("change", change);
    return () => {
      controller.abort();
      media.removeEventListener("change", change);
    };
  }, [user?.id, settingsRevision, report]);
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
        behavior: preferences?.reduced_motion ? "instant" : "smooth",
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
      generationRef.current?.abort();
      if (typeof speechSynthesis !== "undefined") speechSynthesis.cancel();
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
      behavior: preferences?.reduced_motion ? "instant" : "smooth",
    });
  }, [preferences?.reduced_motion]);
  useEffect(() => {
    filtersRef.current = { search, projectId, archived };
    if (!user) return;
    const timer = setTimeout(() => void refreshHistory().catch(report), 250);
    return () => clearTimeout(timer);
  }, [search, projectId, archived, user?.id, refreshHistory, report]);
  useEffect(() => {
    setReadAloud(typeof speechSynthesis !== "undefined");
  }, []);
  const selectedModel = models.find((model) => model.id === modelId);
  const filteredHistory = conversations;
  function newChat() {
    if (busy) return;
    chatRequest.current?.abort();
    setLoadingChat(false);
    setConversationId(null);
    setAssistant(null);
    setMediaMode("chat");
    setInlineMedia([]);
    setMessages([]);
    setMessagePage(1);
    setAttachments([]);
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
    setTemporary(false);
    setAttachments([]);
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
      setInlineMedia([]);
      setAssistant(
        result.conversation.assistant_id
          ? {
              id: result.conversation.assistant_id,
              name: "Custom assistant",
              model_id: null,
            }
          : null,
      );
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
  async function send(
    event?: React.FormEvent,
    override?: {
      text: string;
      conversationId: number | null;
      history: Message[];
      attachmentIds?: number[];
    },
  ) {
    event?.preventDefault();
    const text = (override?.text ?? draft).trim();
    if (!text || busy || loadingChat || initializing) return;
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (mediaMode !== "chat" && !override) {
      if (!mediaKinds.includes(mediaMode))
        return setError("This media engine is not configured.");
      if (text.length > 4000)
        return setError("Media prompts must be at most 4000 characters.");
      const controller = new AbortController();
      generationRef.current = controller;
      setBusy(true);
      setError("");
      try {
        const task = await api<{ id: number }>(
          "media",
          "POST",
          { kind: mediaMode, prompt: text },
          controller.signal,
        );
        if (!controller.signal.aborted) {
          setInlineMedia((ids) => [...ids, task.id]);
          setDraft("");
        }
      } catch (e) {
        report(e);
      } finally {
        if (!controller.signal.aborted) setBusy(false);
      }
      return;
    }
    if (!modelId) {
      setError(
        "No model is available yet. Ask your administrator to enable one.",
      );
      return;
    }
    const history = override?.history ?? messages;
    const id = override ? override.conversationId : conversationId;
    setError("");
    setBusy(true);
    setDraft("");
    setMessages([...history, { role: "user", content: text }]);
    const controller = new AbortController();
    generationRef.current = controller;
    activeStream.current = null;
    let receivedMeta = false;
    try {
      await streamChat(
        {
          model_id: modelId,
          ...(assistant ? { assistant_id: assistant.id } : {}),
          message: text,
          ...(id ? { conversation_id: id } : {}),
          ...(projectId ? { project_id: projectId } : {}),
          temporary,
          web_search: webSearch,
          attachment_ids:
            override?.attachmentIds ?? attachments.map((a) => a.id),
          ...(temporary
            ? {
                history: history.slice(-20).map((m) => ({
                  role: m.role,
                  content: m.content.slice(0, 16000),
                })),
              }
            : {}),
        },
        (event, data) => {
          if (event === "meta") {
            receivedMeta = true;
            const cid = data.conversation_id as number | null;
            setConversationId(cid);
            activeStream.current = {
              conversation_id: cid,
              message_id: data.message_id as number | null,
            };
            setMessages((previous) => [
              ...previous.slice(0, -1),
              {
                ...previous[previous.length - 1],
                id: data.user_message_id as number | undefined,
              },
              {
                id: data.message_id as number | undefined,
                role: "assistant",
                content: "",
                status: "generating",
                sources: data.sources as Message["sources"],
              },
            ]);
          }
          if (event === "delta")
            setMessages((previous) =>
              previous.map((m, i) =>
                i === previous.length - 1
                  ? { ...m, content: m.content + String(data.content) }
                  : m,
              ),
            );
          if (event === "error") setError(String(data.message));
          if (
            event === "done" &&
            data.status === "complete" &&
            preferences?.completion_notifications &&
            document.hidden &&
            "Notification" in window &&
            Notification.permission === "granted"
          ) {
            try {
              new Notification("Openmindai", {
                body: "Your response is ready.",
                tag: "openmindai-response",
                icon: "/openmindai-logo.png",
              });
            } catch {
              /* Mobile browsers may require service-worker notifications. */
            }
          }
          if (event === "done")
            setMessages((previous) =>
              previous.map((m, i) =>
                i === previous.length - 1
                  ? { ...m, status: String(data.status) }
                  : m,
              ),
            );
        },
        controller.signal,
      );
      setAttachments([]);
      if (!temporary) void refreshHistory().catch(report);
    } catch (e) {
      if ((e as Error).name === "AbortError") {
        setMessages((previous) =>
          previous.map((m, i) =>
            i === previous.length - 1 ? { ...m, status: "cancelled" } : m,
          ),
        );
      } else {
        if (!receivedMeta) {
          setMessages(history);
          setDraft(text);
        }
        report(e);
      }
    } finally {
      setBusy(false);
      generationRef.current = null;
      activeStream.current = null;
      inputRef.current?.focus();
    }
  }
  async function stop() {
    const active = activeStream.current;
    if (active?.conversation_id && active.message_id) {
      try {
        await api(
          `conversations/${active.conversation_id}/messages/${active.message_id}/stop`,
          "POST",
        );
      } catch (e) {
        report(e);
      }
    } else generationRef.current?.abort();
  }
  async function branch(index: number, edit = false) {
    if (busy || !conversationId) return;
    const target = messages[index];
    if (!target?.id) return;
    const content = edit
      ? prompt("Edit your message", target.content)
      : target.content;
    if (!content?.trim()) return;
    try {
      const result = await api<{
        conversation_id: number;
        message: string;
        attachment_ids: number[];
      }>(`conversations/${conversationId}/branch`, "POST", {
        message_id: target.id,
      });
      setConversationId(result.conversation_id);
      await send(undefined, {
        text: content,
        conversationId: result.conversation_id,
        history: messages.slice(0, index),
        attachmentIds: result.attachment_ids,
      });
    } catch (e) {
      report(e);
    }
  }
  async function feedback(index: number, value: "up" | "down") {
    if (!conversationId || !messages[index].id) return;
    try {
      await api(
        `conversations/${conversationId}/messages/${messages[index].id}/feedback`,
        "PUT",
        { feedback: messages[index].feedback === value ? null : value },
      );
      setMessages((previous) =>
        previous.map((m, i) =>
          i === index
            ? { ...m, feedback: m.feedback === value ? null : value }
            : m,
        ),
      );
    } catch (e) {
      report(e);
    }
  }
  function handleFile(file: File) {
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (busy || loadingChat || uploading) return;
    if (file.size > 8000000) {
      setError("File exceeds 8 MB.");
      return;
    }
    if (
      /\.(pdf|docx|png|jpe?g|webp|wav|mp3|flac|ogg|webm)$/i.test(file.name) ||
      /^(image|audio)\//.test(file.type)
    )
      setFilePreview(file);
    else void upload(file);
  }
  async function upload(file: File) {
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (file.size > 100000 || attachments.length >= 5) {
      setError("Attach up to five text/code files, at most 100 KB each.");
      return;
    }
    const owner = user.id;
    const controller = new AbortController();
    uploadRequest.current = controller;
    setUploading(true);
    try {
      const content = await file.text();
      if (controller.signal.aborted || identityRef.current !== owner) return;
      const data = await api<{ id: number; name: string }>(
        "attachments",
        "POST",
        { name: file.name, content },
        controller.signal,
      );
      if (identityRef.current === owner)
        setAttachments((previous) => [...previous, data]);
    } catch (e) {
      report(e);
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }
  function speak(text: string) {
    if (typeof speechSynthesis === "undefined") return;
    const voice = speechSynthesis.getVoices().find((v) => v.localService);
    if (!voice) {
      setError("No on-device speech voice is installed.");
      return;
    }
    speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.voice = voice;
    speechSynthesis.speak(utterance);
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
            <Image
              src="/openmindai-logo.png"
              alt="Openmindai"
              width={30}
              height={30}
            />
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
              placeholder="Search all saved chats"
              maxLength={100}
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
        {user && (
          <div className="sidebar-organize">
            <button
              className="sidebar-action"
              onClick={() => setWorkspaceOpen(true)}
            >
              <FolderOpen size={18} />
              Projects & memory
            </button>
            <button
              className="sidebar-action"
              onClick={() => setMediaOpen(true)}
            >
              <Sparkles size={18} /> Media studio
            </button>
            <button
              className="sidebar-action"
              disabled={busy}
              onClick={() => setToolsOpen("assistants")}
            >
              <Code2 size={18} />
              Tools & library
            </button>
            <select
              aria-label="Filter by project"
              value={projectId ?? ""}
              onChange={(e) => {
                setProjectId(e.target.value ? Number(e.target.value) : null);
                newChat();
              }}
            >
              <option value="">All projects</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
            <button
              className="text-button"
              onClick={() => setArchived(!archived)}
            >
              <Archive size={14} />
              {archived ? "Back to active chats" : "Archived chats"}
            </button>
          </div>
        )}
        {user && (
          <button
            className="sidebar-action"
            disabled={busy}
            onClick={() => setCanvasInitial("")}
          >
            <PenLine size={18} />
            Canvas
          </button>
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
                  onClick={() => setToolsChat(chat)}
                  aria-label={`Manage ${chat.title}`}
                >
                  <MoreHorizontal size={14} />
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
                  {user.avatar ? (
                    <img src={user.avatar} alt="" />
                  ) : (
                    user.name.charAt(0).toUpperCase()
                  )}
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
                <span>Openmindai</span>
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
            {user && (
              <>
                <button
                  className={`temporary-toggle ${temporary ? "enabled" : ""}`}
                  disabled={busy}
                  onClick={() => {
                    newChat();
                    setTemporary(!temporary);
                  }}
                  title="Temporary chats aren't saved and don't use memory"
                >
                  {temporary ? "Temporary on" : "Temporary"}
                </button>
                {conversationId && (
                  <button
                    className="icon-button"
                    aria-label="Conversation settings and sharing"
                    onClick={() =>
                      setToolsChat(
                        conversations.find((c) => c.id === conversationId) || {
                          id: conversationId,
                          title: "Conversation",
                          updated_at: "",
                        },
                      )
                    }
                  >
                    <MoreHorizontal size={19} />
                  </button>
                )}
              </>
            )}
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
                <Image
                  src="/openmindai-logo.png"
                  alt="Openmindai"
                  width={46}
                  height={46}
                />
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
                      <Image
                        src="/openmindai-logo.png"
                        alt=""
                        width={23}
                        height={23}
                      />
                    </span>
                  )}
                  <div className="message-body">
                    {message.role === "user" ? (
                      <div className="user-bubble">
                        {message.content}
                        {message.id && (
                          <button
                            className="icon-button edit-message"
                            disabled={busy}
                            onClick={() => void branch(index, true)}
                            aria-label="Edit message"
                          >
                            <Pencil size={14} />
                          </button>
                        )}
                      </div>
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
                        {message.status &&
                          message.status !== "complete" &&
                          message.status !== "generating" && (
                            <small className="muted">
                              {message.status === "cancelled"
                                ? "Stopped"
                                : "Response interrupted"}
                            </small>
                          )}
                        {!!message.sources?.length && (
                          <div className="source-links">
                            {message.sources.map((source) => (
                              <a
                                key={source.url}
                                href={source.url}
                                target="_blank"
                                rel="noopener noreferrer"
                              >
                                {source.title}
                              </a>
                            ))}
                          </div>
                        )}
                        <div className="message-actions">
                          {message.id && (
                            <>
                              <button
                                className={`icon-button ${message.feedback === "up" ? "feedback-selected" : ""}`}
                                aria-label="Good response"
                                onClick={() => void feedback(index, "up")}
                              >
                                <ThumbsUp size={14} />
                              </button>
                              <button
                                className={`icon-button ${message.feedback === "down" ? "feedback-selected" : ""}`}
                                aria-label="Bad response"
                                onClick={() => void feedback(index, "down")}
                              >
                                <ThumbsDown size={14} />
                              </button>
                            </>
                          )}
                          {index > 0 && messages[index - 1].id && (
                            <button
                              className="icon-button"
                              aria-label="Regenerate in a new branch"
                              disabled={busy}
                              onClick={() => void branch(index - 1)}
                            >
                              <RotateCcw size={14} />
                            </button>
                          )}
                          {readAloud && (
                            <button
                              className="icon-button"
                              aria-label="Read aloud using an on-device voice"
                              onClick={() => speak(message.content)}
                            >
                              <Volume2 size={14} />
                            </button>
                          )}
                          <button
                            className="icon-button"
                            aria-label="Open in canvas"
                            onClick={() => setCanvasInitial(message.content)}
                          >
                            <PenLine size={14} />
                          </button>
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
              {busy && messages[messages.length - 1]?.content === "" && (
                <div className="thinking" role="status">
                  <span className="assistant-avatar">
                    <Image
                      src="/openmindai-logo.png"
                      alt=""
                      width={23}
                      height={23}
                    />
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
          {inlineMedia.map((id) => (
            <InlineMedia key={id} id={id} onText={setDraft} />
          ))}
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
          {assistant && (
            <div className="attachment-chips">
              <span>Assistant: {assistant.name}</span>
              <button
                onClick={() => {
                  newChat();
                  setAssistant(null);
                }}
              >
                Leave assistant
              </button>
            </div>
          )}
          {conversationId && (
            <button
              className="text-button"
              disabled={busy}
              onClick={() =>
                void api<{ id: number; title: string }[]>(
                  `conversations/${conversationId}/branches`,
                )
                  .then(setBranches)
                  .catch(report)
              }
            >
              Conversation branches
            </button>
          )}
          <div className="composer-modes">
            <select
              aria-label="Message mode"
              value={mediaMode}
              onChange={(e) => setMediaMode(e.target.value)}
            >
              <option value="chat">Chat</option>
              {["image", "speech", "video"]
                .filter((k) => mediaKinds.includes(k))
                .map((k) => (
                  <option key={k} value={k}>
                    Create {k}
                  </option>
                ))}
            </select>
            <button onClick={() => setToolsOpen("voice")}>Voice</button>
            <button onClick={() => setToolsOpen("research")}>Research</button>
            <button onClick={() => setToolsOpen("code")}>Run Python</button>
          </div>
          {mediaMode !== "chat" && (
            <p className="muted">
              Media tasks are saved separately in Library. They are not
              temporary chat messages.
            </p>
          )}
          <form
            className="composer"
            onSubmit={send}
            onDragOver={(e) => {
              if (e.dataTransfer.types.includes("Files")) e.preventDefault();
            }}
            onDrop={(e) => {
              e.preventDefault();
              const f = e.dataTransfer.files[0];
              if (f) handleFile(f);
            }}
          >
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
                  (preferences?.send_on_enter ?? true) &&
                  e.key === "Enter" &&
                  !e.shiftKey &&
                  !e.nativeEvent.isComposing
                ) {
                  e.preventDefault();
                  void send();
                }
              }}
            />
            <input
              ref={fileRef}
              type="file"
              accept="image/png,image/jpeg,image/webp,audio/*,.pdf,.docx,.webm,.txt,.md,.csv,.json,.py,.js,.jsx,.ts,.tsx,.php,.rs,.go,.java,.c,.cpp,.h,.html,.css,.sql,.yaml,.yml,.xml,.sh,.log"
              hidden
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) handleFile(file);
              }}
            />
            {attachments.length > 0 && (
              <div className="attachment-chips">
                {attachments.map((a) => (
                  <span key={a.id}>
                    <Paperclip size={12} />
                    {a.name}
                    <button
                      type="button"
                      aria-label={`Remove ${a.name}`}
                      onClick={() =>
                        setAttachments((previous) =>
                          previous.filter((f) => f.id !== a.id),
                        )
                      }
                    >
                      <X size={13} />
                    </button>
                  </span>
                ))}
              </div>
            )}
            <div className="composer-toolbar">
              {searchAvailable && (
                <button
                  type="button"
                  className={`temporary-toggle ${webSearch ? "enabled" : ""}`}
                  disabled={busy}
                  onClick={() => setWebSearch(!webSearch)}
                >
                  <Search size={13} />
                  {webSearch ? "Web search on" : "Web search"}
                </button>
              )}
              <button
                type="button"
                className="icon-button"
                disabled={busy || uploading}
                aria-label="Attach a text or code file"
                onClick={() => fileRef.current?.click()}
              >
                {uploading ? (
                  <LoaderCircle size={17} className="spin" />
                ) : (
                  <Paperclip size={17} />
                )}
              </button>
              <span className="composer-hint">
                <Sparkles size={15} />
                {selectedModel?.family || "Your AI, at your pace"}
              </span>
              <div className="composer-right">
                {draft.length > 14000 && (
                  <small>{draft.length.toLocaleString()}/16,000</small>
                )}
                {busy ? (
                  <button
                    type="button"
                    className="send-button"
                    aria-label="Stop generating"
                    onClick={() => void stop()}
                  >
                    <Square size={14} fill="currentColor" />
                  </button>
                ) : (
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
                )}
              </div>
            </div>
          </form>
          <p className="composer-note">
            AI can make mistakes. Check important information.
            <span>Shift + Enter for a new line</span>
          </p>
        </div>
      </main>
      {canvasInitial !== null && (
        <CanvasDialog
          initial={canvasInitial}
          onClose={() => setCanvasInitial(null)}
          onUse={(a) =>
            setAttachments((previous) =>
              [...previous.filter((f) => f.id !== a.id), a].slice(-5),
            )
          }
        />
      )}
      {toolsOpen && user && (
        <ToolsWorkbench
          initial={toolsOpen}
          onClose={() => setToolsOpen(null)}
          onAssistant={(a) => {
            newChat();
            setAssistant(a);
            if (a.model_id) setModelId(a.model_id);
            setToolsOpen(null);
          }}
          onText={(text) => {
            setDraft(text);
            setToolsOpen(null);
          }}
          onAttachment={(file) => {
            setAttachments((old) =>
              [...old.filter((f) => f.id !== file.id), file].slice(-5),
            );
            setToolsOpen(null);
          }}
        />
      )}
      {filePreview && user && (
        <FilePreview
          file={filePreview}
          onClose={() => setFilePreview(null)}
          onTask={(id) => setInlineMedia((old) => [...old, id])}
        />
      )}
      {branches && (
        <Dialog title="Conversation branches" onClose={() => setBranches(null)}>
          <p className="muted">
            Switch between saved response branches. Each branch keeps its own
            history.
          </p>
          {branches.map((b) => (
            <button
              className="manage-card"
              key={b.id}
              onClick={() => {
                setBranches(null);
                void openChat(b.id);
              }}
              aria-current={b.id === conversationId ? "page" : undefined}
            >
              {b.title}
              {b.id === conversationId ? " (current)" : ""}
            </button>
          ))}
        </Dialog>
      )}
      {mediaOpen && (
        <MediaDialog
          onClose={() => setMediaOpen(false)}
          onUse={(text) => {
            setDraft(text);
            setMediaOpen(false);
            setToolsOpen(null);
            setFilePreview(null);
            setInlineMedia([]);
            setAssistant(null);
            setBranches(null);
          }}
        />
      )}
      {workspaceOpen && (
        <WorkspaceDialog
          onAttachment={(a) =>
            setAttachments((previous) =>
              [...previous.filter((f) => f.id !== a.id), a].slice(-5),
            )
          }
          onClose={() => setWorkspaceOpen(false)}
          onProject={(id) => {
            setProjectId(id);
            newChat();
          }}
          onChanged={() => {
            setSettingsRevision((v) => v + 1);
            void api<{ id: number; name: string }[]>("projects")
              .then(setProjects)
              .catch(report);
          }}
        />
      )}
      {toolsChat && (
        <ConversationDialog
          chat={toolsChat}
          onClose={() => setToolsChat(null)}
          onChanged={() => void refreshHistory().catch(report)}
        />
      )}
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
          onChanged={() => setSettingsRevision((v) => v + 1)}
          onDataChanged={() => {
            generationRef.current?.abort();
            chatRequest.current?.abort();
            setBusy(false);
            setLoadingChat(false);
            setConversationId(null);
            setMessages([]);
            setAttachments([]);
            setDraft("");
            void refreshHistory().catch(report);
          }}
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
