import { Suspense, lazy } from "react";
import { BrowserRouter, Route, Routes, useLocation } from "react-router-dom";
import { AuthProvider, RequireAuth } from "./auth";
import { ErrorBoundary } from "./components/ErrorBoundary";
import { Footer } from "./components/Footer";
import { Nav } from "./components/Nav";
import { Ribbon } from "./components/Ribbon";
import { Toaster } from "./components/ui/toast";
import Landing from "./pages/Landing";

// Halaman aplikasi di-lazy-load agar bundel awal (landing) tetap ringan.
const Login = lazy(() => import("./pages/Login"));
const Proposal = lazy(() => import("./pages/Proposal"));
const Bob = lazy(() => import("./pages/Bob"));
const Riwayat = lazy(() => import("./pages/Riwayat"));

function Loading() {
  return (
    <div className="max-w-4xl mx-auto px-4 py-10" role="status" aria-live="polite">
      <div className="skeleton h-6 w-48 rounded-lg" />
      <div className="skeleton h-40 w-full rounded-2xl mt-4" />
      <span className="sr-only">Memuat…</span>
    </div>
  );
}

function Shell() {
  const loc = useLocation();
  if (loc.pathname === "/") {
    return (
      <Routes>
        <Route path="/" element={<Landing />} />
      </Routes>
    );
  }
  return (
    <div className="app-bg min-h-dvh flex flex-col">
      <Nav />
      <Ribbon />
      <div className="flex-1">
        <Suspense fallback={<Loading />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/app" element={<RequireAuth><Proposal /></RequireAuth>} />
            <Route path="/app/:id" element={<RequireAuth><Proposal /></RequireAuth>} />
            <Route path="/bob" element={<RequireAuth><Bob /></RequireAuth>} />
            <Route path="/riwayat" element={<RequireAuth><Riwayat /></RequireAuth>} />
            <Route path="*" element={<RequireAuth><Proposal /></RequireAuth>} />
          </Routes>
        </Suspense>
      </div>
      <Footer />
    </div>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <ErrorBoundary>
        <AuthProvider>
          <Shell />
          <Toaster />
        </AuthProvider>
      </ErrorBoundary>
    </BrowserRouter>
  );
}
