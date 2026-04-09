import express, { Request, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import path from 'path';
import { initAdmin } from './controllers/authController';
import authRoutes from './routes/authRoutes';
import employeeRoutes from './routes/employeeRoutes';

dotenv.config();

const app = express();
const port = process.env.PORT || 3001;

app.use(cors());
app.use(express.json());

// Serve uploaded files statically
app.use('/uploads', express.static(path.join(__dirname, '../uploads')));

// Routes
app.use('/api/auth', authRoutes);
app.use('/api/employees', employeeRoutes);

app.get('/api/health', (req: Request, res: Response) => {
    res.json({ status: 'OK', message: 'Backend is running' });
});

// Initialize DB
initAdmin().catch(console.error);

app.listen(port, () => {
    console.log(`Server is running on port ${port}`);
});
