import React from 'react';
import { LayoutDashboard, UploadCloud, CreditCard, ShieldCheck } from 'lucide-react';
import { ViewState } from '../types';

interface SidebarProps {
  currentView: ViewState;
  onChangeView: (view: ViewState) => void;
}

export const Sidebar: React.FC<SidebarProps> = ({ currentView, onChangeView }) => {
  const menuItems = [
    { id: 'dashboard', label: 'Gestión', icon: LayoutDashboard },
    { id: 'upload', label: 'Portal de Carga', icon: UploadCloud },
    { id: 'editor', label: 'Editor de Carnet', icon: CreditCard },
  ];

  return (
    <aside className="w-64 bg-brand-blue text-white h-screen fixed left-0 top-0 flex flex-col shadow-2xl z-20">
      <div className="p-6 border-b border-blue-800 flex items-center space-x-3">
        <div className="bg-white p-1.5 rounded-lg">
          <ShieldCheck className="w-6 h-6 text-brand-blue" />
        </div>
        <div>
          <h1 className="font-bold text-lg tracking-tight">ID-Flow AI</h1>
          <p className="text-xs text-blue-200">Seguridad Social</p>
        </div>
      </div>

      <nav className="flex-1 py-6 px-3 space-y-2">
        {menuItems.map((item) => {
          const isActive = currentView === item.id;
          return (
            <button
              key={item.id}
              onClick={() => onChangeView(item.id as ViewState)}
              className={`w-full flex items-center space-x-3 px-4 py-3 rounded-xl transition-all duration-200 ${
                isActive 
                  ? 'bg-blue-700 text-white shadow-lg translate-x-1' 
                  : 'text-blue-100 hover:bg-blue-800 hover:text-white'
              }`}
            >
              <item.icon className={`w-5 h-5 ${isActive ? 'text-brand-emerald' : ''}`} />
              <span className="font-medium">{item.label}</span>
            </button>
          );
        })}
      </nav>

      <div className="p-6 border-t border-blue-800">
        <div className="bg-blue-800/50 rounded-xl p-4">
          <p className="text-xs text-blue-200 font-medium mb-1">Estado del Sistema</p>
          <div className="flex items-center space-x-2">
            <span className="w-2 h-2 bg-brand-emerald rounded-full animate-pulse"></span>
            <span className="text-xs text-white">En línea • v2.4.0</span>
          </div>
        </div>
      </div>
    </aside>
  );
};