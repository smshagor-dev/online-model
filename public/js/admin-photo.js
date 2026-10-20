// Resize locally; the server independently verifies every submitted raster image.
for (const field of document.querySelectorAll("[data-photo-field]")) {
  const input = field.querySelector("[data-photo-input]");
  const value = field.querySelector("[data-photo-value]");
  const preview = field.querySelector("[data-photo-preview]");
  const error = field.querySelector("[data-photo-error]");
  const original = { src: preview.getAttribute("src"), hidden: preview.hidden };
  const restorePreview = () => {
    if (original.src) preview.src = original.src;
    else preview.removeAttribute("src");
    preview.hidden = original.hidden;
  };
  let processing = false;
  let version = 0;
  let invalid = false;
  const fail = (message) => {
    error.textContent = message;
    error.hidden = false;
  };
  field.closest("form").addEventListener("submit", (event) => {
    if (processing || invalid) {
      event.preventDefault();
      fail(
        processing
          ? "Please wait for the photo to finish resizing."
          : "Choose a valid PNG, JPEG or WebP photo.",
      );
    }
  });
  field.querySelector("[data-photo-clear]").addEventListener("click", () => {
    ++version;
    input.value = "";
    value.value = "";
    error.hidden = true;
    invalid = false;
    processing = false;
    restorePreview();
  });
  input.addEventListener("change", async () => {
    const current = ++version;
    value.value = "";
    restorePreview();
    error.hidden = true;
    invalid = false;
    const file = input.files?.[0];
    if (!file) {
      processing = false;
      return;
    }
    processing = true;
    let url;
    try {
      if (
        !["image/png", "image/jpeg", "image/webp"].includes(file.type) ||
        file.size > 10_000_000
      )
        throw new Error("Choose a PNG, JPEG or WebP smaller than 10 MB.");
      url = URL.createObjectURL(file);
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error("Cannot read this image."));
        image.src = url;
      });
      if (current !== version) return;
      if (image.naturalWidth * image.naturalHeight > 40_000_000)
        throw new Error("Choose an image smaller than 40 megapixels.");
      const scale = Math.min(
        1,
        512 / image.naturalWidth,
        512 / image.naturalHeight,
      );
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("This browser cannot resize photos.");
      context.fillStyle = "#ffffff";
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      let encoded = "";
      for (const quality of [0.85, 0.65, 0.45, 0.25]) {
        encoded = canvas.toDataURL("image/jpeg", quality);
        if (encoded.split(",")[1].length <= 128000) break;
      }
      if (encoded.split(",")[1].length > 128000)
        throw new Error("Photo is still too large. Choose a simpler image.");
      error.hidden = true;
      value.value = encoded.split(",")[1];
      preview.src = encoded;
      preview.hidden = false;
      input.value = "";
      const remove = field.querySelector("[name=remove_avatar]");
      if (remove) remove.checked = false;
    } catch (e) {
      if (current === version) {
        invalid = true;
        input.value = "";
        fail(e.message);
      }
    } finally {
      if (url) URL.revokeObjectURL(url);
      if (current === version) processing = false;
    }
  });
}
