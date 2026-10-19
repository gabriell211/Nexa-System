import type { ReactElement } from 'react';

/** A mesma marca visual identifica o administrativo e o atendimento técnico. */
export function BrandLogo({ variant = 'login' }: {
  variant?: 'login' | 'sidebar' | 'topbar';
}): ReactElement {
  return <div className={`brand-identity brand-identity--${variant}`} aria-label="Nexa System">
    <span className="brand-identity-icon">
      <img src="/brand-mark.webp" alt="" aria-hidden="true" width="64" height="64" />
    </span>
    <span className="brand-identity-type">
      <span className="brand-identity-name">Ne<span className="highlight">x</span>a</span>
      <span className="brand-identity-subtitle">System · Gestão MPS</span>
    </span>
  </div>;
}
