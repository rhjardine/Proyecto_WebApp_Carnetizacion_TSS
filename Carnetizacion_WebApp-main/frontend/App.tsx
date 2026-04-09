import React, { useState, useEffect } from 'react';
import { Sidebar } from './components/Sidebar';
import { Dashboard } from './components/Dashboard';
import { UploadPortal } from './components/UploadPortal';
import { IDEditor } from './components/IDEditor';
import { Login } from './components/Login';
import api from './services/api';
import { Employee, ViewState, Status } from './types';
import { INITIAL_EMPLOYEES } from './constants';

export default function App() {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [currentView, setCurrentView] = useState<ViewState>('dashboard');
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('token');
    if (token) {
      setIsAuthenticated(true);
      fetchEmployees();
    }
  }, []);

  const fetchEmployees = async () => {
    try {
      const response = await api.get('/employees');
      setEmployees(response.data);
    } catch (error) {
      console.error('Error fetching employees:', error);
    }
  };

  const handleLogin = (user: any) => {
    setIsAuthenticated(true);
    fetchEmployees();
  };

  const handleUpdateStatus = async (id: number, status: Status) => {
    try {
      await api.patch(`/employees/${id}`, { status });
      setEmployees(prev => prev.map(emp =>
        emp.id === id ? { ...emp, status } : emp
      ));
    } catch (error) {
      console.error('Error updating status:', error);
    }
  };

  const handleSelectEmployee = (emp: Employee) => {
    setSelectedEmployee(emp);
    setCurrentView('editor');
  };

  if (!isAuthenticated) {
    return <Login onLogin={handleLogin} />;
  }

  return (
    <div className="flex min-h-screen bg-[#f1f5f9]">
      <Sidebar currentView={currentView} onChangeView={setCurrentView} />

      <main className="flex-1 ml-64 transition-all duration-300">
        {currentView === 'dashboard' && (
          <Dashboard
            employees={employees}
            onSelectEmployee={handleSelectEmployee}
            onUpdateStatus={handleUpdateStatus}
          />
        )}

        {currentView === 'upload' && (
          <UploadPortal />
        )}

        {currentView === 'editor' && selectedEmployee && (
          <IDEditor employee={selectedEmployee} />
        )}
      </main>
    </div>
  );
}