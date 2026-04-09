import React, { useState, useEffect } from 'react';
import { Employee, TemplateYear, IDOrientation } from '../types';
import { QrCode, Download, Layers, CreditCard, Smartphone, FileSpreadsheet, Sparkles, RefreshCw, FileText } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { MOCK_LOGO_URL } from '../constants';

interface IDEditorProps {
  employee: Employee;
}

export const IDEditor: React.FC<IDEditorProps> = ({ employee }) => {
  const [template, setTemplate] = useState<TemplateYear>('2025');
  const [orientation, setOrientation] = useState<IDOrientation>('horizontal');
  
  // Local state to allow AI extraction to update the preview without affecting the global database immediately
  const [previewData, setPreviewData] = useState<Employee>(employee);
  const [isAnalyzing, setIsAnalyzing] = useState(false);

  // Update local state when prop changes, unless we are editing
  useEffect(() => {
    setPreviewData(employee);
  }, [employee]);

  // Helper to get color classes based on template
  const is2025 = template === '2025';
  const isVertical = orientation === 'vertical';

  const handleSmartExtraction = () => {
    setIsAnalyzing(true);
    // Simulate AI processing of a document
    setTimeout(() => {
        setIsAnalyzing(false);
        setPreviewData({
            ...previewData,
            firstName: 'Alberto',
            lastName: 'Castillo',
            role: 'Gerente de Operaciones',
            cedula: 'V-8.999.111',
            photoUrl: 'https://picsum.photos/200/200?random=99', // New photo extracted
            department: 'Operaciones'
        });
    }, 2500);
  };

  return (
    <div className="p-4 md:p-8 max-w-7xl mx-auto min-h-screen flex flex-col md:flex-row gap-8">
      
      {/* Controls Sidebar */}
      <div className="w-full md:w-80 space-y-6">
        
        {/* Smart Extraction Widget */}
        <div className="bg-gradient-to-br from-indigo-50 to-purple-50 p-6 rounded-2xl shadow-sm border border-indigo-100 relative overflow-hidden">
            <div className="absolute top-0 right-0 p-2 opacity-10">
                <Sparkles className="w-24 h-24 text-indigo-600" />
            </div>
            <h3 className="font-bold text-indigo-900 flex items-center gap-2 mb-2 relative z-10">
                <Sparkles className="w-4 h-4 text-indigo-600" />
                Extracción Inteligente
            </h3>
            <p className="text-xs text-indigo-700 mb-4 relative z-10">
                Sube una nómina (Excel), Cédula (JPG) o Ficha (PDF). La IA detectará foto y datos.
            </p>
            
            <button 
                onClick={handleSmartExtraction}
                disabled={isAnalyzing}
                className="w-full relative z-10 bg-white border border-indigo-200 text-indigo-800 hover:bg-indigo-50 font-medium py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-all shadow-sm group"
            >
                {isAnalyzing ? (
                    <>
                        <RefreshCw className="w-4 h-4 animate-spin" />
                        Analizando Doc...
                    </>
                ) : (
                    <>
                        <FileSpreadsheet className="w-4 h-4 text-green-600 group-hover:scale-110 transition-transform" />
                        <FileText className="w-4 h-4 text-red-500 group-hover:scale-110 transition-transform" />
                        <span>Cargar Documento</span>
                    </>
                )}
            </button>
            {isAnalyzing && (
                <div className="mt-2 h-1 w-full bg-indigo-100 rounded-full overflow-hidden">
                    <motion.div 
                        initial={{ width: 0 }}
                        animate={{ width: "100%" }}
                        transition={{ duration: 2.5 }}
                        className="h-full bg-indigo-500"
                    />
                </div>
            )}
        </div>

        {/* Orientation Control */}
        <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
             <h3 className="font-bold text-gray-900 flex items-center gap-2 mb-4">
                <Smartphone className="w-5 h-5 text-gray-500" />
                Orientación
            </h3>
            <div className="flex bg-gray-100 p-1 rounded-xl">
                <button 
                    onClick={() => setOrientation('horizontal')}
                    className={`flex-1 flex items-center justify-center py-2 rounded-lg text-sm font-medium transition-all ${!isVertical ? 'bg-white text-brand-blue shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                >
                    <CreditCard className="w-4 h-4 mr-2 rotate-0" />
                    Horizontal
                </button>
                <button 
                    onClick={() => setOrientation('vertical')}
                    className={`flex-1 flex items-center justify-center py-2 rounded-lg text-sm font-medium transition-all ${isVertical ? 'bg-white text-brand-blue shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                >
                    <CreditCard className="w-4 h-4 mr-2 -rotate-90" />
                    Vertical
                </button>
            </div>
        </div>

        {/* Template Control */}
        <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <h3 className="font-bold text-gray-900 flex items-center gap-2 mb-4">
                <Layers className="w-5 h-5 text-brand-blue" />
                Plantilla Institucional
            </h3>
            
            <div className="space-y-3">
                <button 
                    onClick={() => setTemplate('2024')}
                    className={`w-full text-left p-3 rounded-xl border transition-all flex items-center justify-between ${
                        !is2025 ? 'border-brand-blue bg-blue-50 ring-1 ring-brand-blue' : 'border-gray-200 hover:border-gray-300'
                    }`}
                >
                    <span className="text-sm font-medium text-gray-700">Diseño Clásico 2024</span>
                    {!is2025 && <div className="w-2 h-2 bg-brand-blue rounded-full" />}
                </button>
                <button 
                    onClick={() => setTemplate('2025')}
                    className={`w-full text-left p-3 rounded-xl border transition-all flex items-center justify-between ${
                        is2025 ? 'border-brand-blue bg-blue-50 ring-1 ring-brand-blue' : 'border-gray-200 hover:border-gray-300'
                    }`}
                >
                    <span className="text-sm font-medium text-gray-700">Diseño Moderno 2025</span>
                    {is2025 && <div className="w-2 h-2 bg-brand-blue rounded-full" />}
                </button>
            </div>
        </div>

        {/* Data Display */}
        <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <h3 className="font-bold text-gray-900 flex items-center gap-2 mb-4">
                <CreditCard className="w-5 h-5 text-gray-500" />
                Datos Vinculados
            </h3>
            <div className="space-y-3 text-sm">
                <div>
                    <span className="block text-gray-400 text-xs uppercase">Nombre Completo</span>
                    <motion.span key={previewData.firstName} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="font-medium text-gray-800">
                        {previewData.firstName} {previewData.lastName}
                    </motion.span>
                </div>
                <div>
                    <span className="block text-gray-400 text-xs uppercase">Cédula</span>
                    <motion.span key={previewData.cedula} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="font-medium text-gray-800">
                        {previewData.cedula}
                    </motion.span>
                </div>
                <div>
                    <span className="block text-gray-400 text-xs uppercase">Cargo</span>
                     <motion.span key={previewData.role} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="font-medium text-gray-800">
                        {previewData.role}
                    </motion.span>
                </div>
            </div>
        </div>
        
        <button className="w-full bg-gray-900 hover:bg-gray-800 text-white font-medium py-3 rounded-xl shadow-xl flex items-center justify-center gap-2 transition-all active:scale-95">
            <Download className="w-5 h-5" />
            Generar PDF de Impresión
        </button>
      </div>

      {/* Preview Area */}
      <div className="flex-1 bg-slate-200/50 rounded-3xl flex items-center justify-center p-8 border border-slate-200 relative overflow-hidden min-h-[600px]">
        {/* Background Grid Pattern */}
        <div className="absolute inset-0 opacity-[0.03]" style={{ backgroundImage: 'radial-gradient(#000 1px, transparent 1px)', backgroundSize: '24px 24px' }}></div>
        
        <AnimatePresence mode="wait">
        <motion.div 
            key={`${template}-${orientation}`}
            layout
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.9 }}
            transition={{ duration: 0.4 }}
            className="relative drop-shadow-2xl"
        >
            {/* ---------------- CARD CONTAINER ---------------- */}
            <div 
                className={`
                    relative rounded-2xl overflow-hidden bg-white shadow-inner flex flex-col transition-all duration-500
                    ${isVertical 
                        ? 'w-[340px] aspect-[1/1.58]' // Vertical Aspect Ratio
                        : 'w-[500px] aspect-[1.58/1]' // Horizontal Aspect Ratio
                    }
                    ${is2025 ? 'font-sans' : 'font-serif'}
                `}
            >
                 {/* ================================================================================== */}
                 {/* ================================ HORIZONTAL MODES ================================ */}
                 {/* ================================================================================== */}

                 {!isVertical && is2025 && (
                    <>
                        {/* Header Background */}
                        <div className="h-1/3 bg-[#003366] relative overflow-hidden">
                            <div className="absolute inset-0 bg-gradient-to-r from-blue-900 to-[#003366]"></div>
                            <div className="absolute -bottom-10 -left-10 w-40 h-40 bg-white/10 rounded-full blur-xl"></div>
                            <div className="absolute top-0 right-0 w-60 h-full bg-gradient-to-l from-emerald-500/20 to-transparent"></div>
                            
                            <div className="relative z-10 flex items-center justify-between px-6 pt-5">
                                <div className="text-white">
                                    <h1 className="text-[10px] uppercase tracking-widest opacity-80">República Bolivariana de Venezuela</h1>
                                    <h2 className="text-sm font-bold leading-tight mt-0.5 w-3/4">Tesorería de Seguridad Social</h2>
                                </div>
                                <div className="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                                   <img src={MOCK_LOGO_URL} alt="Logo" className="w-8 h-8 object-contain brightness-0 invert" />
                                </div>
                            </div>
                        </div>

                        {/* Body */}
                        <div className="flex-1 relative bg-white px-6 pt-10 pb-4">
                            <div className="absolute top-[-40px] left-6 w-28 h-28 bg-white p-1 rounded-xl shadow-lg">
                                <motion.img key={previewData.photoUrl} initial={{opacity:0}} animate={{opacity:1}} src={previewData.photoUrl} alt="Employee" className="w-full h-full object-cover rounded-lg bg-gray-200" />
                            </div>

                            <div className="flex justify-end items-start h-full">
                                <div className="w-2/3 pl-4 flex flex-col justify-between h-full">
                                    <div className="text-right">
                                        <h3 className="text-2xl font-bold text-gray-900 leading-tight">{previewData.firstName}</h3>
                                        <h3 className="text-xl font-medium text-gray-600">{previewData.lastName}</h3>
                                        <div className="mt-2 inline-block bg-blue-50 text-[#003366] px-3 py-1 rounded-md text-xs font-bold uppercase tracking-wider">
                                            {previewData.role}
                                        </div>
                                    </div>

                                    <div className="flex items-end justify-between mt-4">
                                        <div className="text-left">
                                            <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-0.5">Cédula de Identidad</p>
                                            <p className="text-lg font-mono font-bold text-gray-800">{previewData.cedula}</p>
                                        </div>
                                        <div className="border-4 border-white shadow-sm">
                                             <QrCode className="w-16 h-16 text-gray-900" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="h-3 bg-gradient-to-r from-yellow-400 via-blue-500 to-red-500"></div>
                    </>
                )}

                {!isVertical && !is2025 && (
                    <>
                        <div className="absolute top-0 left-0 w-4 h-full bg-[#003366]"></div>
                        <div className="flex-1 pl-4 flex flex-col">
                            <div className="h-16 border-b-2 border-[#003366] flex items-center justify-between px-6 mr-4 mt-4">
                                <div className="flex items-center gap-3">
                                    <img src={MOCK_LOGO_URL} alt="Logo" className="w-10 h-10 object-contain" />
                                    <div>
                                        <h1 className="text-[10px] font-bold text-gray-500 uppercase">Gobierno Bolivariano de Venezuela</h1>
                                        <h2 className="text-xs font-bold text-[#003366] uppercase">Tesorería de Seguridad Social</h2>
                                    </div>
                                </div>
                            </div>

                            <div className="flex-1 p-6 flex gap-6 items-center">
                                <div className="w-24 h-32 border border-gray-300 bg-gray-100 shrink-0">
                                    <motion.img key={previewData.photoUrl} initial={{opacity:0}} animate={{opacity:1}} src={previewData.photoUrl} alt="Employee" className="w-full h-full object-cover grayscale" />
                                </div>
                                <div className="flex-1 space-y-1">
                                    <h3 className="text-xl font-bold text-[#003366] uppercase">{previewData.firstName} {previewData.lastName}</h3>
                                    <p className="text-sm text-gray-600 font-semibold">{previewData.role}</p>
                                    <p className="text-xs text-gray-400">{previewData.department}</p>
                                    
                                    <div className="mt-4 pt-2 border-t border-gray-200 flex justify-between items-center">
                                        <div>
                                            <span className="text-[10px] block text-gray-400">C.I.</span>
                                            <span className="font-mono font-bold text-lg">{previewData.cedula}</span>
                                        </div>
                                        <QrCode className="w-10 h-10 text-gray-800" />
                                    </div>
                                </div>
                            </div>
                            
                            <div className="h-6 bg-[#003366] w-full text-white text-[8px] flex items-center justify-center uppercase tracking-widest mr-4 mb-4 rounded-r-full">
                                Válido hasta Diciembre 2024
                            </div>
                        </div>
                    </>
                )}


                {/* ================================================================================== */}
                {/* ================================= VERTICAL MODES ================================= */}
                {/* ================================================================================== */}

                {isVertical && (
                    <>
                        {/* Vertical Header */}
                        <div className="h-1/4 bg-[#003366] relative flex flex-col items-center justify-center text-center p-4 overflow-hidden">
                             <div className="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10"></div>
                             <div className="relative z-10 flex flex-col items-center gap-2">
                                <div className="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-md">
                                    <img src={MOCK_LOGO_URL} alt="Logo" className="w-6 h-6 object-contain" />
                                </div>
                                <div className="text-white">
                                    <h1 className="text-[8px] uppercase tracking-widest opacity-80">República Bolivariana de Venezuela</h1>
                                    <h2 className="text-xs font-bold leading-tight mt-1">Tesorería de Seguridad Social</h2>
                                </div>
                             </div>
                             {/* Decorative Curve */}
                             <div className="absolute bottom-[-20px] left-0 w-full h-10 bg-white rounded-t-[50%]"></div>
                        </div>

                        {/* Vertical Body */}
                        <div className="flex-1 bg-white relative flex flex-col items-center pt-2 px-6">
                            
                            {/* Photo Container */}
                            <div className="w-32 h-32 rounded-full p-1 bg-gradient-to-tr from-brand-blue to-brand-emerald shadow-lg mb-4">
                                <motion.img 
                                    key={previewData.photoUrl}
                                    initial={{opacity:0, scale: 0.8}} 
                                    animate={{opacity:1, scale: 1}} 
                                    src={previewData.photoUrl} 
                                    alt="Employee" 
                                    className="w-full h-full object-cover rounded-full border-4 border-white bg-gray-100" 
                                />
                            </div>

                            {/* Text Info */}
                            <div className="text-center w-full space-y-1 mb-6">
                                <h2 className="text-2xl font-bold text-gray-900 leading-none">{previewData.firstName}</h2>
                                <h2 className="text-xl font-medium text-gray-500 leading-tight">{previewData.lastName}</h2>
                                
                                <div className="my-3 h-0.5 w-16 bg-gray-100 mx-auto"></div>

                                <p className="text-xs font-bold text-[#003366] uppercase tracking-wider">{previewData.role}</p>
                                <p className="text-[10px] text-gray-400">{previewData.department}</p>
                            </div>

                            {/* ID and QR */}
                            <div className="mt-auto mb-6 w-full bg-slate-50 rounded-xl p-3 flex items-center justify-between border border-slate-100">
                                <div className="text-left">
                                     <p className="text-[9px] text-gray-400 uppercase font-bold">Cédula de Identidad</p>
                                     <p className="text-base font-mono font-bold text-gray-800">{previewData.cedula}</p>
                                </div>
                                <QrCode className="w-10 h-10 text-[#003366]" />
                            </div>
                        </div>

                        {/* Vertical Footer */}
                        <div className="h-2 w-full flex">
                            <div className="flex-1 bg-yellow-400"></div>
                            <div className="flex-1 bg-blue-600"></div>
                            <div className="flex-1 bg-red-600"></div>
                        </div>
                    </>
                )}

            </div>
        </motion.div>
        </AnimatePresence>
        
        <p className="absolute bottom-4 text-slate-400 text-xs font-mono">
            {isVertical ? 'Formato Vertical (54x86mm)' : 'Formato Horizontal (86x54mm)'} • {is2025 ? 'v2.5' : 'v1.0'}
        </p>
      </div>
    </div>
  );
};