import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore
} from "@whiskeysockets/baileys";
import express from "express";
import qrcode from "qrcode-terminal";
import fs from "fs";
import path from "path";
import { fileURLToPath } from 'url';
import pino from 'pino';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
app.use(express.json({ limit: "50mb" }));

let sock = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 10;

// Logger silencioso
const logger = pino({ level: 'silent' });

function borrarAuth() {
  const authPath = path.join(__dirname, "auth");
  if (fs.existsSync(authPath)) {
    fs.rmSync(authPath, { recursive: true, force: true });
    console.log("✅ Carpeta auth eliminada");
  }
}

async function conectarWhatsApp() {
  try {
    const { state, saveCreds } = await useMultiFileAuthState("auth");
    const { version } = await fetchLatestBaileysVersion();

    console.log(`📱 Usando versión: ${version.join('.')}`);

    // Crear socket de manera diferente
    const { default: makeWASocketActual } = await import("@whiskeysockets/baileys");
    
    sock = makeWASocketActual({
      version,
      auth: state,
      logger,
      printQRInTerminal: false,
      browser: ["ControlProduccionBot", "Chrome", "1.0"],
      syncFullHistory: false,
      connectTimeoutMs: 60000,
      keepAliveIntervalMs: 30000,
      generateHighQualityLinkPreview: false,
    });

    sock.ev.on("creds.update", saveCreds);

    sock.ev.on("connection.update", (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        console.log("\n🟢 ESCANEA ESTE QR CON TU CELULAR:");
        qrcode.generate(qr, { small: true });
        reconnectAttempts = 0;
      }

      if (connection === "open") {
        console.log("✅ WHATSAPP CONECTADO Y LISTO");
        reconnectAttempts = 0;
      }

      if (connection === "close") {
        const statusCode = lastDisconnect?.error?.output?.statusCode;
        
        console.log("❌ Conexión cerrada. Código:", statusCode);

        if (statusCode === DisconnectReason.loggedOut) {
          console.log("🚪 Sesión cerrada - necesitas escanear QR nuevamente");
          borrarAuth();
          setTimeout(conectarWhatsApp, 1000);
        } else if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
          reconnectAttempts++;
          console.log(`🔄 Reintentando (${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})...`);
          setTimeout(conectarWhatsApp, 5000);
        }
      }
    });

  } catch (err) {
    console.error("💥 Error grave:", err.message);
    console.log("🔄 Reiniciando en 10s...");
    setTimeout(conectarWhatsApp, 10000);
  }
}

// Endpoint de texto
app.post("/send", async (req, res) => {
  const { numero, mensaje } = req.body;
  
  if (!sock) {
    return res.status(503).json({ status: "error", detalle: "Bot no conectado" });
  }

  try {
    const jid = `${numero}@s.whatsapp.net`;
    await sock.sendMessage(jid, { text: mensaje });
    console.log(`📤 Mensaje enviado a ${numero}`);
    res.json({ status: "ok" });
  } catch (e) {
    console.error("Error:", e.message);
    res.status(500).json({ status: "error", detalle: e.message });
  }
});

// Endpoint para PDF
app.post("/send-media", async (req, res) => {
  if (!sock) {
    return res.status(503).json({ status: "error", detalle: "Bot no conectado" });
  }

  const { numero, caption = "", base64, filename = "documento.pdf" } = req.body;

  if (!numero || !base64) {
    return res.status(400).json({ status: "error", detalle: "Faltan datos" });
  }

  try {
    const buffer = Buffer.from(base64, "base64");
    const jid = `${numero}@s.whatsapp.net`;

    await sock.sendMessage(jid, {
      document: buffer,
      mimetype: "application/pdf",
      fileName: filename,
      caption: caption
    });

    console.log(`📎 PDF enviado a ${numero}`);
    res.json({ status: "ok" });
  } catch (e) {
    console.error("Error PDF:", e.message);
    res.status(500).json({ status: "error", detalle: e.message });
  }
});

// Health check
app.get("/health", (req, res) => {
  res.json({ 
    status: sock?.user ? "connected" : "disconnected",
    reconnectAttempts 
  });
});

const PORT = 3000;
app.listen(PORT, () => {
  console.log(`🚀 API en http://localhost:${PORT}`);
  console.log("📌 POST /send");
  console.log("📌 POST /send-media");
  console.log("📌 GET  /health");
});

conectarWhatsApp();

process.on('SIGINT', () => {
  console.log('\n👋 Cerrando...');
  process.exit(0);
});