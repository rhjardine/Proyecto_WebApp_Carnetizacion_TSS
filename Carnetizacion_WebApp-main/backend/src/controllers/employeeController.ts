import { Request, Response } from 'express';
import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

export const getEmployees = async (req: Request, res: Response) => {
    try {
        const employees = await prisma.employee.findMany({
            orderBy: { createdAt: 'desc' }
        });
        res.json(employees);
    } catch (error) {
        res.status(500).json({ message: 'Error fetching employees' });
    }
};

export const createEmployee = async (req: Request, res: Response) => {
    try {
        const { cedula, nombre, cargo, departamento, tipoSangre, nss } = req.body;

        // Check duplication
        const existing = await prisma.employee.findUnique({ where: { cedula } });
        if (existing) {
            return res.status(400).json({ message: 'Employee with this ID already exists' });
        }

        const employee = await prisma.employee.create({
            data: {
                cedula,
                nombre,
                cargo,
                departamento,
                tipoSangre,
                nss,
                status: 'Active'
            },
        });
        res.status(201).json(employee);
    } catch (error) {
        res.status(500).json({ message: 'Error creating employee', error });
    }
};

export const updateEmployeeStatus = async (req: Request, res: Response) => {
    const { id } = req.params;
    const { status } = req.body;

    try {
        const employee = await prisma.employee.update({
            where: { id: Number(id) },
            data: { status }
        });
        res.json(employee);
    } catch (error) {
        res.status(500).json({ message: 'Error updating status' });
    }
};

// Placeholder for upload (needs Multer config in routes)
export const logUpload = async (req: Request, res: Response) => {
    // Logic to handle file path update after Multer upload
    const { id } = req.params;
    const file = req.file;

    if (!file) {
        return res.status(400).json({ message: 'No file uploaded' });
    }

    try {
        const photoUrl = `/uploads/${file.filename}`;
        const employee = await prisma.employee.update({
            where: { id: Number(id) },
            data: { photoUrl }
        });
        res.json({ message: 'Photo uploaded', photoUrl, employee });
    } catch (error) {
        res.status(500).json({ message: 'Database update failed' });
    }
};
