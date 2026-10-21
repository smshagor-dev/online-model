import assert from "node:assert/strict";
import test from "node:test";
import { NVIDIA_HOSTED_MODELS, NVIDIA_AUTO_MODEL_ID, nvidiaModels, isNvidiaModelId } from "../src/lib/nvidia-chat.ts";

test("OpenMindAI hosted agent IDs map to the intended NVIDIA catalog", () => {
  assert.deepEqual(NVIDIA_HOSTED_MODELS.map((model) => model.slug), [
    "openmindai-super", "openmindai-ultra", "openmindai-lite",
  ]);
  assert.equal(NVIDIA_AUTO_MODEL_ID, -4);
  assert.ok(isNvidiaModelId(-4));
  assert.ok(isNvidiaModelId(-2));
  assert.ok(isNvidiaModelId(-3));
  assert.ok(isNvidiaModelId(-1));
  assert.equal(isNvidiaModelId(99), false);
  assert.deepEqual(nvidiaModels(["openmindai-super"]).map((model) => model.name), ["OpenMindAI Auto", "OpenMindAI Super"]);
});
