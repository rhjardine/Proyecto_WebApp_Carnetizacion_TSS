import { Employee } from './types';

export const INITIAL_EMPLOYEES: Employee[] = [
  {
    id: 1,
    cedula: 'V-12.345.678',
    firstName: 'María',
    lastName: 'Rodríguez',
    role: 'Analista de Tesorería',
    department: 'Finanzas',
    status: 'Pendiente',
    photoUrl: 'https://picsum.photos/200/200?random=1',
    createdAt: '2023-10-24'
  },
  {
    id: 2,
    cedula: 'V-23.456.789',
    firstName: 'Carlos',
    lastName: 'Pérez',
    role: 'Director General',
    department: 'Dirección',
    status: 'Verificado',
    photoUrl: 'https://picsum.photos/200/200?random=2',
    createdAt: '2023-10-23'
  },
  {
    id: 3,
    cedula: 'V-15.888.999',
    firstName: 'Ana',
    lastName: 'García',
    role: 'Coordinadora de RRHH',
    department: 'Recursos Humanos',
    status: 'Impreso',
    photoUrl: 'https://picsum.photos/200/200?random=3',
    createdAt: '2023-10-20'
  }
];

export const MOCK_LOGO_URL = "https://upload.wikimedia.org/wikipedia/commons/thumb/0/0a/Logotipo_del_Gobierno_de_la_Rep%C3%BAblica_Bolivariana_de_Venezuela.svg/2560px-Logotipo_del_Gobierno_de_la_Rep%C3%BAblica_Bolivariana_de_Venezuela.svg.png";