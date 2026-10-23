import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { api, authHeaders } from "./lib/utils";

export type User = { id: number; nama: string; peran: string };

type Auth = {
  user: User | null;
  ready: boolean;
  login: (nama: string, pw: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const Ctx = createContext<Auth>({ user: null, ready: false, login: async () => {}, logout: async () => {}, refresh: async () => {} });

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);

  const refresh = useCallback(async () => {
    try {
      setUser(await api<User>("/api/me", { headers: authHeaders() }));
    } catch {
      setUser(null);
    } finally {
      setReady(true);
    }
  }, []);

  useEffect(() => { refresh(); }, [refresh]);

  const login = useCallback(async (nama: string, pw: string) => {
    const j = await api<{ token: string }>("/api/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ nama, pw }),
    });
    try { sessionStorage.setItem("bm_token", j.token); } catch {}
    await refresh();
  }, [refresh]);

  const logout = useCallback(async () => {
    await api("/api/logout", { method: "POST", headers: authHeaders() }).catch(() => {});
    try { sessionStorage.removeItem("bm_token"); } catch {}
    document.cookie = "bm_token=; Path=/; Max-Age=0; SameSite=Strict";
    setUser(null);
  }, []);

  return <Ctx.Provider value={{ user, ready, login, logout, refresh }}>{children}</Ctx.Provider>;
}

export const useAuth = () => useContext(Ctx);

export function RequireAuth({ children }: { children: ReactNode }) {
  const { user, ready } = useAuth();
  const loc = useLocation();

  if (!ready) {
    return (
      <div className="max-w-4xl mx-auto px-4 py-10" role="status" aria-live="polite">
        <div className="skeleton h-6 w-48 rounded-lg" />
        <div className="skeleton h-40 w-full rounded-2xl mt-4" />
        <span className="sr-only">Memuat…</span>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: loc }} replace />;
  }

  return <>{children}</>;
}
