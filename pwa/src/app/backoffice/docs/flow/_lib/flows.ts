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
 * Friendly, non-technical "how it works" flows shown at
 * `/backoffice/docs/flow` ({@link DocsFlowPage}). Plain data — no React — so the
 * page stays a thin renderer and new flows are just new entries here (mirrors
 * how `roadmap.ts` drives the roadmap page). Each step pairs a plain-language
 * explanation with an optional "behind the scenes" technical aside, so the same
 * page works for a manager skimming the journey and a curious dev.
 */
export type FlowTone = "brand" | "success" | "warning" | "accent";

export interface FlowStep {
  icon: LucideIcon;
  tone: FlowTone;
  /** Friendly, human title. */
  title: string;
  /** One or two sentences anyone can understand. */
  plain: string;
  /** Optional "behind the scenes" note for the technically curious. */
  tech?: string;
}

export interface AnalogyItem {
  role: string;
  maps: string;
}

export interface FlowAnalogy {
  intro: string;
  items: AnalogyItem[];
}

export interface Flow {
  id: string;
  title: string;
  intro: string;
  analogy?: FlowAnalogy;
  steps: FlowStep[];
}

const requestLifecycle: Flow = {
  id: "request-lifecycle",
  title: "El viaje de una petición",
  intro:
    "Cada vez que haces algo en ERPify (guardar, buscar, editar), tu acción hace un pequeño viaje de ida y vuelta. Aquí lo cuentas paso a paso, sin tecnicismos.",
  analogy: {
    intro: "Piensa en ERPify como un restaurante:",
    items: [
      { role: "Tú, el cliente", maps: "haces tu pedido usando la app" },
      { role: "El camarero (la app/PWA)", maps: "toma nota y la lleva a cocina" },
      { role: "La puerta de cocina (FrankenPHP)", maps: "deja pasar cada pedido a quien le toca" },
      { role: "El chef (la API)", maps: "lo prepara siguiendo las recetas (las reglas)" },
      { role: "La despensa (la base de datos)", maps: "guarda y saca los ingredientes (los datos)" },
      { role: "La campana de «¡listo!» (los eventos)", maps: "avisa de que algo ha ocurrido" },
      { role: "Los pinches (los workers)", maps: "hacen tareas extra sin frenar el servicio" },
      { role: "El panel en vivo (Mercure)", maps: "todos ven el estado al momento" },
    ],
  },
  steps: [
    {
      icon: MousePointerClick,
      tone: "brand",
      title: "Tú haces algo",
      plain:
        "Pulsas un botón o abres una pantalla; por ejemplo, guardar un banco nuevo.",
      tech: "Una interacción en el navegador.",
    },
    {
      icon: LayoutDashboard,
      tone: "brand",
      title: "La app prepara la petición",
      plain:
        "La aplicación que ves recoge tu acción y la convierte en una petición clara para el sistema.",
      tech: "La PWA (Next.js) hace una llamada al backend.",
    },
    {
      icon: DoorOpen,
      tone: "accent",
      title: "El portero la recibe",
      plain:
        "Un portero muy rápido recibe tu petición y la dirige al sitio correcto: a la propia app o al cerebro del sistema.",
      tech: "FrankenPHP/Caddy enruta /api/* a la API y el resto a la PWA.",
    },
    {
      icon: BrainCircuit,
      tone: "success",
      title: "El cerebro decide",
      plain:
        "El sistema comprueba que todo es válido y aplica las reglas de negocio antes de tocar nada.",
      tech: "Un controlador de Symfony delega en un caso de uso (capa de aplicación).",
    },
    {
      icon: Database,
      tone: "success",
      title: "La memoria guarda o consulta",
      plain:
        "La información se guarda o se lee de forma segura y permanente, para que nunca se pierda.",
      tech: "PostgreSQL a través de Doctrine.",
    },
    {
      icon: Zap,
      tone: "warning",
      title: "«¡Ha pasado algo!»",
      plain:
        "Cada cambio importante deja una nota de que ocurrió, como un aviso: «se ha creado un banco».",
      tech: "Se registra un evento de dominio y se guarda (auditoría / outbox).",
    },
    {
      icon: Cog,
      tone: "warning",
      title: "Los ayudantes trabajan en segundo plano",
      plain:
        "Las tareas que pueden esperar (enviar un email, avisar a otros) se hacen aparte, para que tú no tengas que esperar.",
      tech: "Symfony Messenger entrega el evento a un worker asíncrono.",
    },
    {
      icon: RadioTower,
      tone: "brand",
      title: "Aviso instantáneo a quien esté mirando",
      plain:
        "Si otra persona está viendo la misma pantalla, la ve actualizarse al instante, sin recargar nada.",
      tech: "Mercure publica el cambio y la PWA actualiza la pantalla en vivo (tiempo real).",
    },
    {
      icon: CheckCircle2,
      tone: "success",
      title: "Ves el resultado",
      plain:
        "Recibes la respuesta y la pantalla muestra el cambio. Y si algo va mal, un mensaje claro te explica qué pasó. Listo.",
      tech: "Respuesta JSON; los errores siguen un formato estándar y entendible.",
    },
  ],
};

export const flows: Flow[] = [requestLifecycle];
