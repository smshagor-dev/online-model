// Convert browser recordings and uploaded audio to the mono 16-bit WAV expected
// by the hosted transcription service. This runs only on the user's device.
export async function audioAsWavBase64(file: Blob): Promise<string> {
  if (file.size > 8_000_000 || !file.size) throw new Error("Audio must be at most 8 MB.");
  const ctx = new AudioContext();
  try {
    const sound = await ctx.decodeAudioData(await file.arrayBuffer());
    if (sound.duration <= 0 || sound.duration > 120)
      throw new Error("Recordings must be shorter than two minutes.");
    const rate = 16000;
    const frames = Math.ceil(sound.duration * rate);
    const buffer = new Uint8Array(44 + frames * 2);
    const view = new DataView(buffer.buffer);
    const label = (at: number, str: string) => {
      for (let i = 0; i < str.length; i++) view.setUint8(at + i, str.charCodeAt(i));
    };
    label(0, "RIFF"); view.setUint32(4, buffer.length - 8, true);
    label(8, "WAVE"); label(12, "fmt ");
    view.setUint32(16, 16, true); view.setUint16(20, 1, true);
    view.setUint16(22, 1, true); view.setUint32(24, rate, true);
    view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true);
    view.setUint16(34, 16, true); label(36, "data");
    view.setUint32(40, frames * 2, true);
    const channels = Array.from({ length: sound.numberOfChannels },
      (_, i) => sound.getChannelData(i));
    for (let i = 0; i < frames; i++) {
      const index = Math.min(sound.length - 1, Math.round(i * sound.sampleRate / rate));
      const mixed = channels.reduce((sum, ch) => sum + ch[index], 0) / channels.length;
      const sample = Math.max(-1, Math.min(1, mixed));
      view.setInt16(44 + i * 2, sample < 0 ? Math.round(sample * 32768) : Math.round(sample * 32767), true);
    }
    let binary = "";
    // Avoid stack overflows when encoding multi-megabyte recordings.
    for (let i = 0; i < buffer.length; i += 16384)
      binary += String.fromCharCode(...buffer.subarray(i, i + 16384));
    return btoa(binary);
  } finally {
    await ctx.close();
  }
}
