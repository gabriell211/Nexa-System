import React, { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import * as api from './api';
import type { Person } from './types';

type AuthContextValue = {
  user: Person | null;
  restoring: boolean;
  offline: boolean;
  signIn: (loginName: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  retrySession: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }): React.JSX.Element {
  const [user, setUser] = useState<Person | null>(null);
  const [restoring, setRestoring] = useState(true);
  const [offline, setOffline] = useState(false);
  const queries = useQueryClient();

  useEffect(() => {
    let alive = true;
    api.onExpired(() => {
      if (alive) {
        setUser(null);
        queries.clear();
      }
    });
    api.restoreSession().then(result => {
      if (!alive) return;
      setUser(result.state === 'authenticated' ? result.user : null);
      setOffline(result.state === 'offline');
    }).catch(() => {
      if (alive) {
        setUser(null);
        setOffline(true);
      }
    }).finally(() => {
      if (alive) setRestoring(false);
    });
    return () => {
      alive = false;
      api.onExpired(null);
    };
  }, [queries]);

  async function retrySession(): Promise<void> {
    setRestoring(true);
    try {
      const result = await api.restoreSession();
      setUser(result.state === 'authenticated' ? result.user : null);
      setOffline(result.state === 'offline');
    } finally {
      setRestoring(false);
    }
  }

  async function signIn(loginName: string, password: string): Promise<void> {
    const response = await api.login(loginName, password);
    queries.clear();
    setOffline(false);
    setUser(response.user);
  }

  async function signOut(): Promise<void> {
    try {
      await api.logout();
    } finally {
      queries.clear();
      setUser(null);
      setOffline(false);
    }
  }

  return <AuthContext.Provider value={{ user, restoring, offline, signIn, signOut, retrySession }}>
    {children}
  </AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const result = useContext(AuthContext);
  if (!result) throw new Error('AuthProvider não foi carregado.');
  return result;
}
