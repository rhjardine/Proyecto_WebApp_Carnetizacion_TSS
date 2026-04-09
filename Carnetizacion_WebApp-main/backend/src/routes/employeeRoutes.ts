import { Router } from 'express';
import multer from 'multer';
import path from 'path';
import {
    getEmployees,
    createEmployee,
    updateEmployeeStatus,
    logUpload
} from '../controllers/employeeController';
import { authenticateToken } from '../middleware/authMiddleware';

const router = Router();

// Multer config
const storage = multer.diskStorage({
    destination: (req, file, cb) => {
        cb(null, 'uploads/');
    },
    filename: (req, file, cb) => {
        const uniqueSuffix = Date.now() + '-' + Math.round(Math.random() * 1E9);
        cb(null, uniqueSuffix + path.extname(file.originalname));
    }
});
const upload = multer({ storage });

router.get('/', authenticateToken, getEmployees);
router.post('/', authenticateToken, createEmployee);
router.patch('/:id', authenticateToken, updateEmployeeStatus);
router.post('/:id/upload', authenticateToken, upload.single('photo'), logUpload);

export default router;
