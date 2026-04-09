import React, { useState } from 'react';
import { Employee } from '../types';
import { Search, Sparkles, Filter, CheckCircle2, Clock, Printer, XCircle, MoreVertical } from 'lucide-react';
import { motion } from 'framer-motion';

interface DashboardProps {
  employees: Employee[];
  onSelectEmployee: (emp: Employee) => void;
  onUpdateStatus: (id: number, status: Employee['status']) => void;
}

export const Dashboard: React.FC<DashboardProps> = ({ employees, onSelectEmployee, onUpdateStatus }) => {
  const [isMatching, setIsMatching] = useState(false);

  const handleAutoMatch = () => {
    setIsMatching(true);
    // Simulate AI processing
    setTimeout(() => {
      employees.forEach(emp => {
        if (emp.status === 'Pendiente') {
          onUpdateStatus(emp.id, 'Verificado');
        }
      });
      setIsMatching(false);
    }, 2000);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'Pendiente':
        return <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"><Clock className="w-3 h-3 mr-1" /> Pendiente</span>;
      case 'Verificado':
        return <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><CheckCircle2 className="w-3 h-3 mr-1" /> Verificado</span>;
      case 'Impreso':
        return <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><Printer className="w-3 h-3 mr-1" /> Impreso</span>;
      default:
        return <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" /> Rechazado</span>;
    }
  };

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-8">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-gray-900">Gestión de Personal</h2>
          <p className="text-gray-500 text-sm mt-1">Administre las solicitudes de carnetización y estados.</p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={handleAutoMatch}
            disabled={isMatching}
            className="flex items-center space-x-2 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-5 py-2.5 rounded-xl shadow-lg shadow-indigo-200 transition-all disabled:opacity-70"
          >
            {isMatching ? (
              <>
                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Analizando BD...</span>
              </>
            ) : (
              <>
                <Sparkles className="w-4 h-4" />
                <span>Auto-Match AI</span>
              </>
            )}
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {[
          { label: 'Total Solicitudes', val: employees.length, color: 'border-l-4 border-blue-500' },
          { label: 'Pendientes', val: employees.filter(e => e.status === 'Pendiente').length, color: 'border-l-4 border-yellow-500' },
          { label: 'Verificados', val: employees.filter(e => e.status === 'Verificado').length, color: 'border-l-4 border-green-500' },
          { label: 'Impresos', val: employees.filter(e => e.status === 'Impreso').length, color: 'border-l-4 border-gray-500' },
        ].map((stat, idx) => (
          <div key={idx} className={`bg-white p-4 rounded-xl shadow-sm ${stat.color} flex flex-col`}>
            <span className="text-gray-500 text-xs font-semibold uppercase tracking-wider">{stat.label}</span>
            <span className="text-2xl font-bold text-gray-900 mt-1">{stat.val}</span>
          </div>
        ))}
      </div>

      {/* Filters & Search */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
          <div className="relative max-w-sm w-full">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input
              type="text"
              placeholder="Buscar por cédula o nombre..."
              className="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-blue/20 focus:border-brand-blue transition-colors"
            />
          </div>
          <button className="flex items-center text-gray-600 hover:text-gray-900 text-sm font-medium px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors">
            <Filter className="w-4 h-4 mr-2" />
            Filtros
          </button>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-gray-600">
            <thead className="bg-gray-50 text-gray-500 font-semibold uppercase tracking-wider text-xs">
              <tr>
                <th className="px-6 py-4">Funcionario</th>
                <th className="px-6 py-4">Cédula</th>
                <th className="px-6 py-4">Cargo / Dept</th>
                <th className="px-6 py-4">Estatus</th>
                <th className="px-6 py-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {employees.map((emp) => (
                <motion.tr
                  layoutId={emp.id}
                  key={emp.id}
                  onClick={() => onSelectEmployee(emp)}
                  className="group hover:bg-blue-50/50 transition-colors cursor-pointer"
                >
                  <td className="px-6 py-4 flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full overflow-hidden border-2 border-white shadow-sm ring-2 ring-transparent group-hover:ring-blue-100 transition-all">
                      <img src={emp.photoUrl} alt={emp.firstName} className="h-full w-full object-cover" />
                    </div>
                    <div>
                      <div className="font-semibold text-gray-900">{emp.firstName} {emp.lastName}</div>
                      <div className="text-xs text-gray-400">{emp.id}</div>
                    </div>
                  </td>
                  <td className="px-6 py-4 font-mono text-gray-500">{emp.cedula}</td>
                  <td className="px-6 py-4">
                    <div className="text-gray-900 font-medium">{emp.role}</div>
                    <div className="text-xs text-gray-400">{emp.department}</div>
                  </td>
                  <td className="px-6 py-4">
                    {getStatusBadge(emp.status)}
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="p-2 text-gray-400 hover:text-gray-600 rounded-full hover:bg-gray-100">
                      <MoreVertical className="w-4 h-4" />
                    </button>
                  </td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};