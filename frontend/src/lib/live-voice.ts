// Browser-independent voice activity state; the UI owns microphone and recording lifecycle.
export class VoiceActivity {
  speaking = false;
  private activeSince: number | null = null;
  private quietSince: number | null = null;
  update(
    level: number,
    now: number,
    threshold = 0.025,
  ): "start" | "end" | null {
    if (level >= threshold) {
      this.quietSince = null;
      if (this.activeSince === null) this.activeSince = now;
      if (!this.speaking && now - this.activeSince >= 160) {
        this.speaking = true;
        return "start";
      }
    } else {
      this.activeSince = null;
      if (this.quietSince === null) this.quietSince = now;
      if (this.speaking && now - this.quietSince >= 800) {
        this.speaking = false;
        return "end";
      }
    }
    return null;
  }
  reset() {
    this.speaking = false;
    this.activeSince = null;
    this.quietSince = null;
  }
}
export function takeSentence(buffer: string): [string, string] {
  const match = /[.!?।](?:\s|$)/u.exec(buffer);
  if (!match && buffer.length > 240) {
    const space = buffer.lastIndexOf(" ", 240);
    const end = space > 120 ? space + 1 : 240;
    return [buffer.slice(0, end), buffer.slice(end)];
  }
  return match
    ? [
        buffer.slice(0, match.index + match[0].length),
        buffer.slice(match.index + match[0].length),
      ]
    : ["", buffer];
}
