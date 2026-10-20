"use client";
import {
  BookOpen, FlaskConical, FolderOpen, Globe, ImagePlus, Paperclip, PanelsTopLeft,
} from "lucide-react";

export type WelcomeToolAction =
  | "files" | "library" | "image" | "tabs" | "web"
  | "project" | "research" | "voice" | "code" | "planner";

export default function WelcomeToolsMenu({
  position, onClose, busy, signedIn, searchAvailable, imageAvailable, mediaKinds, mediaMode, onMediaMode,
  webSearch, deepResearch, searchTimeRange, onSearchTimeRange, onSelect,
}: {
  position: { top: number; left: number; width: number; maxHeight: number };
  onClose: () => void;
  busy: boolean;
  signedIn: boolean;
  searchAvailable: boolean;
  imageAvailable: boolean;
  mediaKinds: string[];
  mediaMode: string;
  onMediaMode: (mode: string) => void;
  webSearch: boolean;
  deepResearch: boolean;
  searchTimeRange: string;
  onSearchTimeRange: (range: string) => void;
  onSelect: (action: WelcomeToolAction) => void;
}) {
  const items = [
    { action: "files", icon: Paperclip, title: "Add photos & files", subtitle: "Upload from your computer", blocked: false, active: false },
    { action: "library", icon: BookOpen, title: "Add from library", subtitle: "Browse and search your files", blocked: false, active: false },
    { action: "image", icon: ImagePlus, title: "Create image", subtitle: !signedIn ? "Sign in to generate images" : imageAvailable ? "Visualize anything" : "Image runtime not configured", blocked: !imageAvailable, active: false },
    { action: "tabs", icon: PanelsTopLeft, title: "Add browser tabs", subtitle: "Paste links from your open tabs", blocked: false, active: false },
    { action: "web", icon: Globe, title: "Web search", subtitle: !signedIn ? "Sign in to search the web" : searchAvailable ? "Find real-time news and information" : "Search provider not configured", blocked: !searchAvailable, active: webSearch && !deepResearch },
    { action: "project", icon: FolderOpen, title: "Work in a project", subtitle: "Start a chat in a project", blocked: false, active: false },
    { action: "research", icon: FlaskConical, title: "Deep research", subtitle: !signedIn ? "Sign in to start detailed research" : searchAvailable ? "Research and prepare a detailed report" : "Search provider not configured", blocked: !searchAvailable, active: deepResearch },
  ] as const;
  return (
    <div className="composer-tools-menu" role="dialog" aria-label="Chat tools" style={position}>
      <div className="welcome-tools-heading">
        <p className="popover-heading">Choose a tool</p>
        <button className="welcome-tools-close" aria-label="Close tools"
          type="button" onClick={onClose}>Close</button>
      </div>
      {items.map((item) => (
        <button
          key={item.action}
          type="button"
          className={"welcome-tool-item" + (item.active ? " is-active" : "")}
          disabled={busy || (signedIn && item.blocked)}
          aria-pressed={item.action === "web" || item.action === "research" ? item.active : undefined}
          title={item.blocked ? item.subtitle : undefined}
          onClick={() => onSelect(item.action)}
        >
          <item.icon size={20} aria-hidden="true" />
          <span><strong>{item.title}</strong><small>{item.subtitle}</small></span>
        </button>
      ))}
      <div className="welcome-tools-divider" />
      <p className="popover-heading">More tools</p>
      <div className="welcome-tools-extras">
        <button type="button" disabled={busy} onClick={() => onSelect("voice")}>Voice</button>
        <button type="button" disabled={busy} onClick={() => onSelect("code")}>Run Python</button>
        <button type="button" disabled={busy} onClick={() => onSelect("planner")}>Research planner</button>
      </div>
      {mediaKinds.some((kind) => ["image", "speech", "video", "document_create"].includes(kind)) && (
        <label className="welcome-tools-select">Media mode
          <select aria-label="Media task mode" value={mediaMode} disabled={busy} onChange={(e) => onMediaMode(e.target.value)}>
            <option value="chat">Chat</option>
            {["image", "speech", "video", "document_create"].filter((kind) => mediaKinds.includes(kind)).map((kind) => (
              <option key={kind} value={kind}>{kind === "document_create" ? "Create document" : `Create ${kind}`}</option>
            ))}
          </select>
        </label>
      )}
      {searchAvailable && webSearch && (
        <label className="welcome-tools-select">Search freshness
          <select aria-label="Search freshness" value={searchTimeRange} disabled={busy} onChange={(e) => onSearchTimeRange(e.target.value)}>
            <option value="">Any time</option>
            <option value="day">Past day</option>
            <option value="week">Past week</option>
            <option value="month">Past month</option>
            <option value="year">Past year</option>
          </select>
        </label>
      )}
    </div>
  );
}
