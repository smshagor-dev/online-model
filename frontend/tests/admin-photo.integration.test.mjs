import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFile } from "node:fs/promises";
const source = await readFile(
  new URL("../../public/js/admin-photo.js", import.meta.url),
  "utf8",
);
function fixture() {
  const handlers = {};
  const form = {
    addEventListener: (event, fn) => (handlers["form:" + event] = fn),
  };
  const input = {
    files: [],
    value: "",
    addEventListener: (event, fn) => (handlers[event] = fn),
  };
  const hidden = { value: "" },
    preview = {
      src: "data:image/png;base64,existing",
      hidden: false,
      getAttribute() {
        return this.src;
      },
      removeAttribute() {
        this.src = "";
      },
    },
    error = { hidden: true, textContent: "" },
    remove = { checked: true };
  const clear = {
    addEventListener: (event, fn) => (handlers["clear:" + event] = fn),
  };
  const elements = {
    "[data-photo-input]": input,
    "[data-photo-value]": hidden,
    "[data-photo-preview]": preview,
    "[data-photo-error]": error,
    "[name=remove_avatar]": remove,
    "[data-photo-clear]": clear,
  };
  const field = {
    querySelector: (selector) => elements[selector],
    closest: () => form,
  };
  const canvas = {
    width: 0,
    height: 0,
    getContext: () => ({ fillRect() {}, drawImage() {} }),
    toDataURL: () => "data:image/jpeg;base64,YWJj",
  };
  const released = [];
  class Image {
    naturalWidth = 2000;
    naturalHeight = 1000;
    set src(v) {
      queueMicrotask(() => this.onload());
    }
  }
  vm.runInNewContext(source, {
    document: { querySelectorAll: () => [field], createElement: () => canvas },
    Image,
    URL: {
      createObjectURL: () => "blob:fixture",
      revokeObjectURL: (url) => released.push(url),
    },
  });
  return { handlers, input, hidden, preview, error, remove, canvas, released };
}
test("admin photo resize blocks early submit and posts only a bounded resized raster", async () => {
  const f = fixture();
  f.input.files = [{ type: "image/png", size: 2_000_000 }];
  f.input.value = "photo.png";
  const work = f.handlers.change();
  let prevented = false;
  f.handlers["form:submit"]({ preventDefault: () => (prevented = true) });
  assert.equal(prevented, true);
  await work;
  assert.equal(f.hidden.value, "YWJj");
  assert.equal(f.input.value, "");
  assert.equal(f.remove.checked, false);
  assert.ok(f.canvas.width <= 512 && f.canvas.height <= 512);
  assert.equal(f.canvas.width / f.canvas.height, 2);
  assert.deepEqual(f.released, ["blob:fixture"]);
  prevented = false;
  f.handlers["form:submit"]({ preventDefault: () => (prevented = true) });
  assert.equal(prevented, false);
  f.handlers["clear:click"]();
  assert.equal(f.hidden.value, "");
  assert.equal(f.preview.src, "data:image/png;base64,existing");
});
test("admin photo rejects SVG and clearing an invalid selection allows a photo-free form", async () => {
  const f = fixture();
  f.input.files = [{ type: "image/svg+xml", size: 100 }];
  await f.handlers.change();
  assert.equal(f.hidden.value, "");
  assert.equal(f.error.hidden, false);
  let prevented = false;
  f.handlers["form:submit"]({ preventDefault: () => (prevented = true) });
  assert.equal(prevented, true);
  f.handlers["clear:click"]();
  prevented = false;
  f.handlers["form:submit"]({ preventDefault: () => (prevented = true) });
  assert.equal(prevented, false);
});
