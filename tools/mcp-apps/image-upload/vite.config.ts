import { defineConfig } from "vite";
import { viteSingleFile } from "vite-plugin-singlefile";

export default defineConfig({
  plugins: [viteSingleFile()],
  build: {
    outDir: "../../../public/wp-content/plugins/nhk-core/resources/ui",
    emptyOutDir: false,
    sourcemap: false,
    minify: false,
    rollupOptions: { input: "image-upload.html" },
  },
});
