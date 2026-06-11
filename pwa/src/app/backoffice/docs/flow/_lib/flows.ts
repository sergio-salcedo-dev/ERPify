import {
  LucideIcon,
  MousePointerClick,
  LayoutDashboard,
  DoorOpen,
  BrainCircuit,
  Database,
  Zap,
  Cog,
  RadioTower,
  CheckCircle2,
} from "lucide-react";

/**
 * Technical-but-accessible "how it works" flows shown at
 * `/backoffice/docs/flow` ({@link DocsFlowPage}). Plain data — no React — so the
 * page stays a thin renderer and new flows are just new entries here (mirrors
 * how `roadmap.ts` drives the roadmap page). The voice is a developer explaining
 * the real pieces by their real names, in language a 16-year-old can follow:
 * `plain` builds the mental model, the optional `tech` aside names the exact
 * stack for whoever wants to go deeper.
 */
export type FlowTone = "brand" | "success" | "warning" | "accent";

export interface FlowStep {
  icon: LucideIcon;
  tone: FlowTone;
  /** Short, concrete title — names the real piece doing the work. */
  title: string;
  /** One or two sentences anyone can understand, using real concepts. */
  plain: string;
  /** Optional "behind the scenes" note with the exact technology. */
  tech?: string;
}

export interface Flow {
  id: string;
  title: string;
  intro: string;
  steps: FlowStep[];
}

const requestLifecycle: Flow = {
  id: "request-lifecycle",
  title: "El viaje de una petición",
  intro:
    "Cada vez que haces algo en ERPify (guardar, buscar, editar), tu acción recorre el sistema de punta a punta y vuelve. Este es el recorrido real, pieza a pieza y con sus nombres reales.",
  steps: [
    {
      icon: MousePointerClick,
      tone: "brand",
      title: "Acción en el navegador",
      plain:
        "Pulsas «Guardar» en la interfaz. El navegador no guarda nada por sí mismo: su trabajo es capturar tu acción y preparársela al servidor, que es donde viven los datos.",
      tech: "Un evento de UI en la PWA (Next.js / React).",
    },
    {
      icon: LayoutDashboard,
      tone: "brand",
      title: "Petición HTTP",
      plain:
        "La aplicación convierte tu acción en una petición HTTP: un mensaje estructurado que dice qué quieres hacer y con qué datos. Todo lo que ves en cualquier app web viaja así.",
      tech: "La PWA llama a la API con fetch; los datos van como JSON.",
    },
    {
      icon: DoorOpen,
      tone: "accent",
      title: "Enrutado en el servidor",
      plain:
        "En el servidor hay un único punto de entrada que mira cada petición y la dirige: las que empiezan por /api/* van a la API (la lógica); el resto, a la interfaz. Una sola puerta, bien vigilada.",
      tech: "FrankenPHP (con Caddy embebido) actúa de reverse proxy.",
    },
    {
      icon: BrainCircuit,
      tone: "success",
      title: "La API aplica las reglas",
      plain:
        "La API comprueba que los datos son válidos y que la operación está permitida, y solo entonces ejecuta la lógica de negocio. Nada toca los datos sin pasar por las reglas.",
      tech: "Un controlador Symfony delega en un caso de uso (capa de aplicación).",
    },
    {
      icon: Database,
      tone: "success",
      title: "Base de datos",
      plain:
        "Los datos se guardan o se leen en la base de datos: un almacén estructurado y permanente. Guardar ahí es lo que hace que tu cambio sobreviva a cierres, fallos y reinicios.",
      tech: "PostgreSQL a través de Doctrine, dentro de una transacción.",
    },
    {
      icon: Zap,
      tone: "warning",
      title: "Evento de dominio",
      plain:
        "Además de guardar, el sistema deja constancia del hecho con un nombre: «se ha creado un banco». Otras piezas pueden reaccionar a ese evento sin conocerse entre sí — así el sistema crece sin enredarse.",
      tech: "El evento de dominio se registra y persiste (auditoría / outbox).",
    },
    {
      icon: Cog,
      tone: "warning",
      title: "Cola y worker",
      plain:
        "Lo que puede esperar (enviar un email, avisar a otros sistemas) se pone en una cola y lo procesa un worker: un proceso aparte que trabaja en segundo plano. Tu petición responde rápido, y si algo falla por el camino, se reintenta.",
      tech: "Symfony Messenger entrega el evento a un worker asíncrono.",
    },
    {
      icon: RadioTower,
      tone: "brand",
      title: "Tiempo real",
      plain:
        "El servidor publica el cambio y todos los navegadores suscritos lo reciben al instante por una conexión que se queda abierta. Por eso otra persona ve tu cambio sin recargar la página.",
      tech: "Mercure (server-sent events); la PWA actualiza la vista al recibirlo.",
    },
    {
      icon: CheckCircle2,
      tone: "success",
      title: "Respuesta",
      plain:
        "La API responde con el resultado y la interfaz lo refleja. Si algo va mal, recibes un error con un formato estándar que explica qué pasó y permite rastrearlo.",
      tech: "Respuesta JSON; los errores siguen RFC 9457 (Problem Details).",
    },
  ],
};

export const flows: Flow[] = [requestLifecycle];
