import test from "node:test";
import assert from "node:assert/strict";
import { VoiceActivity, takeSentence } from "../src/lib/live-voice.ts";

test("voice detector ignores short noise, detects speech and waits for real silence", () => {
  const vad = new VoiceActivity();
  assert.equal(vad.update(0.1, 0), null);
  assert.equal(vad.update(0.1, 80), null);
  assert.equal(vad.update(0, 100), null);
  assert.equal(vad.update(0.1, 200), null);
  assert.equal(vad.update(0.1, 400), "start");
  assert.equal(vad.update(0, 500), null);
  assert.equal(vad.update(0.1, 900), null);
  assert.equal(vad.update(0, 1000), null);
  assert.equal(vad.update(0, 1800), "end");
  assert.equal(vad.speaking, false);
  vad.update(0.1, 2000);
  vad.update(0.1, 2200);
  vad.reset();
  assert.equal(vad.speaking, false);
});
test("streamed voice playback emits full English and Bangla sentences without losing remainder", () => {
  assert.deepEqual(takeSentence("Hello. Next fragment"), [
    "Hello. ",
    "Next fragment",
  ]);
  assert.deepEqual(takeSentence("ভালো আছি। পরের কথা"), [
    "ভালো আছি। ",
    "পরের কথা",
  ]);
  assert.deepEqual(takeSentence("Not finished yet"), ["", "Not finished yet"]);
});

test("long speech chunks make playback progress even without punctuation", () => {
  const original = "Long answer without punctuation ".repeat(20);
  let rest = original;
  let played = "";
  let sentence;
  do {
    [sentence, rest] = takeSentence(rest);
    played += sentence;
  } while (sentence);
  assert.ok(played.length > 240);
  assert.equal(played + rest, original);
  assert.ok(rest.length <= 240);
});
