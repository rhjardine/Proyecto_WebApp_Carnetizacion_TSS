export type Status = 'Pendiente' | 'Verificado' | 'Impreso' | 'Rechazado';

export interface Employee {
  id: number;
  cedula: string;
  firstName: string;
  lastName: string;
  role: string;
  department: string;
  status: Status;
  photoUrl: string;
  createdAt: string;
}

export type ViewState = 'dashboard' | 'upload' | 'editor';

export type TemplateYear = '2024' | '2025';

export type IDOrientation = 'horizontal' | 'vertical';

export interface AIAnalysisStep {
  label: string;
  status: 'waiting' | 'processing' | 'done';
}