const { createApp } = require('./app');

const app = createApp({
  usePersistentSessionStore: true,
  enableDbHealthcheck: true,
});

const port = Number(process.env.PORT || 3000);
app.listen(port, () => {
  console.log(`Aura-Ai JavaScript ativo em http://localhost:${port}`);
});
