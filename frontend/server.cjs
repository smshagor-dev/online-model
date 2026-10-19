// Optional startup file for a Node-capable cPanel/Passenger host.
const http = require("node:http");
const next = require("next");
const port = Number(process.env.PORT || 3000);
const hostname = process.env.HOSTNAME || "0.0.0.0";
const app = next({ dev: false, dir: __dirname, hostname, port });
app
  .prepare()
  .then(() => {
    const handle = app.getRequestHandler();
    http
      .createServer((request, response) => handle(request, response))
      .listen(port, hostname, () =>
        console.log(`Openmindai listening on port ${port}`),
      );
  })
  .catch(() => {
    console.error(
      "Unable to start Openmindai. Check the build and environment.",
    );
    process.exit(1);
  });
