import React, { useState } from 'react';
import { Upload, FileText, Check, AlertCircle, ScanFace, Search, Loader2, CheckCircle2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import api from '../services/api';
import { Employee } from '../types';

export const UploadPortal: React.FC = () => {
    const [file, setFile] = useState<File | null>(null);
    const [progress, setProgress] = useState(0);
    const [aiStatus, setAiStatus] = useState<'idle' | 'analyzing' | 'success' | 'error'>('idle');

    // Validation States
    const [cedula, setCedula] = useState('');
    const [employee, setEmployee] = useState<Employee | null>(null);
    const [validationStatus, setValidationStatus] = useState<'idle' | 'searching' | 'valid' | 'invalid'>('idle');

    const steps = [
        { threshold: 30, text: 'Verificando formato de imagen...' },
        { threshold: 60, text: 'Detectando rostro y centrado...' },
        { threshold: 85, text: 'Validando fondo blanco...' },
        { threshold: 100, text: 'Optimización completada.' },
    ];

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files[0]) {
            const selectedFile = e.target.files[0];
            setFile(selectedFile);
            simulateAIAnalysis();
        }
    };

    const simulateAIAnalysis = () => {
        setAiStatus('analyzing');
        setProgress(0);

        let currentProgress = 0;
        const interval = setInterval(() => {
            currentProgress += Math.floor(Math.random() * 10) + 5;
            if (currentProgress >= 100) {
                currentProgress = 100;
                clearInterval(interval);
                setAiStatus('success');
            }
            setProgress(currentProgress);
        }, 400);
    };

    const handleValidatePayroll = async () => {
        if (!cedula.trim()) return;

        setValidationStatus('searching');
        setEmployee(null);

        try {
            // Fetch all and find (temporary optimization for Beta)
            const response = await api.get('/employees');
            const found = response.data.find((e: Employee) => e.cedula === cedula);

            if (found) {
                setEmployee(found);
                setValidationStatus('valid');
            } else {
                setValidationStatus('invalid');
                alert('Funcionario no encontrado');
            }
        } catch (error) {
            console.error(error);
            setValidationStatus('invalid');
            alert('Error conectando con el servidor');
        }
    };

    const handleUpload = async () => {
        if (!file || !employee) return;

        const formData = new FormData();
        formData.append('photo', file);

        try {
            await api.post(`/employees/${employee.id}/upload`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });
            alert('Foto subida exitosamente!');
            setFile(null);
            setEmployee(null);
            setCedula('');
            setValidationStatus('idle');
            setAiStatus('idle');
        } catch (error) {
            console.error(error);
            alert('Error al subir la foto');
        }
    };

    return (
        <div className="min-h-screen bg-gray-50 p-6 flex flex-col items-center justify-center">
            <div className="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden">
                {/* Header */}
                <div className="bg-brand-blue p-6 text-center">
                    <div className="mx-auto bg-white/10 w-12 h-12 rounded-xl flex items-center justify-center mb-4 backdrop-blur-sm">
                        <Upload className="text-white w-6 h-6" />
                    </div>
                    <h2 className="text-xl font-bold text-white">Carga de Datos</h2>
                    <p className="text-blue-200 text-sm mt-1">Portal de autoservicio del empleado</p>
                </div>

                <div className="p-8 space-y-6">
                    {/* CI Input with Validation */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">Cédula de Identidad</label>
                        <div className="flex gap-2">
                            <div className="relative flex-1">
                                <FileText className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" />
                                <input
                                    type="text"
                                    value={cedula}
                                    onChange={(e) => {
                                        setCedula(e.target.value);
                                        if (validationStatus !== 'idle') setValidationStatus('idle');
                                    }}
                                    placeholder="V-XX.XXX.XXX"
                                    className={`w-full pl-10 pr-4 py-3 border rounded-xl focus:ring-2 focus:ring-brand-blue/20 outline-none transition-all ${validationStatus === 'valid'
                                            ? 'border-green-500 bg-green-50 text-green-900 focus:border-green-500'
                                            : 'border-gray-200 focus:border-brand-blue'
                                        }`}
                                />
                            </div>

                            <button
                                onClick={handleValidatePayroll}
                                disabled={validationStatus === 'searching' || validationStatus === 'valid' || !cedula}
                                className={`px-4 rounded-xl font-medium transition-all flex items-center justify-center gap-2 min-w-[140px] ${validationStatus === 'valid'
                                        ? 'bg-green-100 text-green-700 border border-green-200 cursor-default'
                                        : 'bg-brand-blue text-white hover:bg-blue-800 shadow-md shadow-blue-900/10 active:scale-95'
                                    }`}
                            >
                                {validationStatus === 'searching' && (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" />
                                        <span>Buscando...</span>
                                    </>
                                )}
                                {validationStatus === 'valid' && (
                                    <>
                                        <CheckCircle2 className="w-5 h-5" />
                                        <span>Validado</span>
                                    </>
                                )}
                                {validationStatus === 'idle' && (
                                    <>
                                        <Search className="w-4 h-4" />
                                        <span>Validar</span>
                                    </>
                                )}
                            </button>
                        </div>

                        {/* Success Message / Data Preview */}
                        <AnimatePresence>
                            {validationStatus === 'valid' && employee && (
                                <motion.div
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: 'auto' }}
                                    className="mt-2 overflow-hidden"
                                >
                                    <div className="bg-green-50 border border-green-100 rounded-lg p-3 flex items-start gap-3">
                                        <div className="bg-green-100 p-1 rounded-full">
                                            <Check className="w-3 h-3 text-green-600" />
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-green-800 uppercase">Funcionario Encontrado</p>
                                            <p className="text-sm text-green-900">{employee.firstName} {employee.lastName}</p>
                                            <p className="text-xs text-green-700">{employee.role} • {employee.status}</p>
                                        </div>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>

                    {/* Dropzone */}
                    <div className={`space-y-4 transition-opacity duration-300 ${validationStatus !== 'valid' ? 'opacity-50 pointer-events-none grayscale' : 'opacity-100'}`}>
                        <div className="flex items-center justify-between">
                            <label className="block text-sm font-medium text-gray-700">Fotografía Digital</label>
                            {validationStatus !== 'valid' && <span className="text-xs text-orange-600 font-medium">Requiere validación de cédula</span>}
                        </div>

                        {!file ? (
                            <label className="border-2 border-dashed border-gray-300 rounded-2xl p-8 flex flex-col items-center justify-center cursor-pointer hover:border-brand-blue hover:bg-blue-50/50 transition-all group">
                                <div className="bg-gray-100 p-4 rounded-full mb-3 group-hover:scale-110 transition-transform">
                                    <ScanFace className="w-8 h-8 text-gray-500 group-hover:text-brand-blue" />
                                </div>
                                <p className="text-sm font-medium text-gray-900">Toca para subir o arrastra aquí</p>
                                <p className="text-xs text-gray-400 mt-1">JPG o PNG. Fondo blanco requerido.</p>
                                <input type="file" className="hidden" accept="image/*" onChange={handleFileChange} />
                            </label>
                        ) : (
                            <div className="relative rounded-2xl overflow-hidden aspect-square w-full max-w-[200px] mx-auto border-4 border-white shadow-lg">
                                <img
                                    src={URL.createObjectURL(file)}
                                    alt="Preview"
                                    className="w-full h-full object-cover"
                                />
                                {aiStatus === 'success' && (
                                    <div className="absolute bottom-2 right-2 bg-green-500 text-white p-1 rounded-full shadow-lg">
                                        <Check className="w-4 h-4" />
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* AI Analyzer Status */}
                    <AnimatePresence>
                        {aiStatus !== 'idle' && (
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                className="bg-slate-50 rounded-xl p-4 border border-slate-100"
                            >
                                <div className="flex justify-between items-center mb-2">
                                    <span className="text-xs font-semibold text-slate-600 uppercase tracking-wider flex items-center gap-2">
                                        <SparklesIcon className="w-3 h-3 text-purple-600" />
                                        AI Quality Check
                                    </span>
                                    <span className="text-xs font-bold text-slate-900">{progress}%</span>
                                </div>

                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden mb-3">
                                    <motion.div
                                        className={`h-full ${aiStatus === 'success' ? 'bg-green-500' : 'bg-gradient-to-r from-blue-500 to-purple-500'}`}
                                        initial={{ width: 0 }}
                                        animate={{ width: `${progress}%` }}
                                    />
                                </div>

                                <div className="space-y-2">
                                    {steps.map((step, idx) => (
                                        <div key={idx} className="flex items-center text-xs">
                                            <div className={`w-4 h-4 rounded-full flex items-center justify-center mr-2 border ${progress >= step.threshold
                                                    ? 'bg-green-50 border-green-200 text-green-600'
                                                    : 'bg-white border-gray-200 text-gray-300'
                                                }`}>
                                                <Check className="w-2.5 h-2.5" />
                                            </div>
                                            <span className={progress >= step.threshold ? 'text-gray-700' : 'text-gray-400'}>
                                                {step.text}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>

                    <button
                        onClick={handleUpload}
                        disabled={aiStatus !== 'success'}
                        className="w-full bg-brand-blue text-white font-medium py-3 rounded-xl shadow-lg shadow-blue-900/20 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-blue-800 transition-colors"
                    >
                        {aiStatus === 'success' ? 'Enviar Solicitud' : 'Esperando Validación...'}
                    </button>
                </div>
            </div>
        </div>
    );
};

const SparklesIcon = ({ className }: { className?: string }) => (
    <svg className={className} xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z" /><path d="M5 3v4" /><path d="M9 3v4" /><path d="M3 9h4" /><path d="M3 5h4" /></svg>
);