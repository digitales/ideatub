// PDF Tools - Client-side PDF processing using pdf-lib
// All operations happen in the browser - files never leave the user's device

import { PDFDocument } from 'pdf-lib';

// Make PDFDocument available globally for inline scripts
window.PDFLib = { PDFDocument };

/**
 * Merge multiple PDF files into one
 */
export async function mergePdfs(files) {
    const mergedPdf = await PDFDocument.create();
    
    for (const file of files) {
        const arrayBuffer = await file.arrayBuffer();
        const pdf = await PDFDocument.load(arrayBuffer);
        const pages = await mergedPdf.copyPages(pdf, pdf.getPageIndices());
        pages.forEach((page) => mergedPdf.addPage(page));
    }
    
    const pdfBytes = await mergedPdf.save();
    const blob = new Blob([pdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    
    return {
        url,
        filename: 'merged.pdf',
        blob,
    };
}

/**
 * Split PDF by page numbers (e.g., "1,3,5-7")
 */
export async function splitPdf(file, pageNumbers) {
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await PDFDocument.load(arrayBuffer);
    
    // Parse page numbers string (e.g., "1,3,5-7")
    const pages = parsePageNumbers(pageNumbers, pdf.getPageCount());
    
    const files = [];
    for (const pageNum of pages) {
        const newPdf = await PDFDocument.create();
        const [copiedPage] = await newPdf.copyPages(pdf, [pageNum - 1]);
        newPdf.addPage(copiedPage);
        
        const pdfBytes = await newPdf.save();
        const blob = new Blob([pdfBytes], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        
        files.push({ url, filename: `page-${pageNum}.pdf`, blob });
    }
    
    return { files };
}

/**
 * Split PDF into chunks of N pages
 */
export async function splitPdfIntoChunks(file, pagesPerChunk) {
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await PDFDocument.load(arrayBuffer);
    const totalPages = pdf.getPageCount();
    
    const files = [];
    for (let start = 0; start < totalPages; start += pagesPerChunk) {
        const end = Math.min(start + pagesPerChunk, totalPages);
        const newPdf = await PDFDocument.create();
        const pageIndices = Array.from({ length: end - start }, (_, i) => start + i);
        const copiedPages = await newPdf.copyPages(pdf, pageIndices);
        copiedPages.forEach((page) => newPdf.addPage(page));
        
        const pdfBytes = await newPdf.save();
        const blob = new Blob([pdfBytes], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        
        files.push({ url, filename: `chunk-${Math.floor(start / pagesPerChunk) + 1}.pdf`, blob });
    }
    
    return { files };
}

/**
 * Compress PDF by re-encoding
 */
export async function compressPdf(file, quality = 'medium') {
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await PDFDocument.load(arrayBuffer);
    
    // Re-save with compression options
    const pdfBytes = await pdf.save({
        useObjectStreams: quality === 'low',
        addDefaultPage: false,
    });
    
    const blob = new Blob([pdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    
    return {
        url,
        filename: 'compressed.pdf',
        blob,
        size: blob.size,
    };
}

/**
 * Convert PDF pages to images
 */
export async function pdfToImage(file, pages, format = 'png') {
    // Note: pdf-lib doesn't support PDF to image conversion directly
    // This would require a library like pdf.js or canvas API
    // For now, we'll use a simplified approach with canvas
    
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await PDFDocument.load(arrayBuffer);
    
    // This is a placeholder - actual implementation would use pdf.js or similar
    // to render PDF pages to canvas and then convert to images
    throw new Error('PDF to image conversion requires pdf.js library. This feature will be implemented with pdf.js.');
}

/**
 * Convert images to PDF
 */
export async function imageToPdf(files) {
    const pdf = await PDFDocument.create();
    
    for (const file of files) {
        const arrayBuffer = await file.arrayBuffer();
        let image;
        
        if (file.type === 'image/png') {
            image = await pdf.embedPng(arrayBuffer);
        } else if (file.type === 'image/jpeg' || file.type === 'image/jpg') {
            image = await pdf.embedJpg(arrayBuffer);
        } else {
            throw new Error(`Unsupported image type: ${file.type}`);
        }
        
        const page = pdf.addPage([image.width, image.height]);
        page.drawImage(image, {
            x: 0,
            y: 0,
            width: image.width,
            height: image.height,
        });
    }
    
    const pdfBytes = await pdf.save();
    const blob = new Blob([pdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    
    return {
        url,
        filename: 'converted.pdf',
        blob,
    };
}

/**
 * Rotate PDF pages
 */
export async function rotatePdf(file, pages, angle) {
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await PDFDocument.load(arrayBuffer);
    
    // Convert angle to radians
    const radians = (angle * Math.PI) / 180;
    
    // Rotate specified pages
    for (const pageNum of pages) {
        const page = pdf.getPage(pageNum - 1);
        const { width, height } = page.getSize();
        
        // Set rotation
        page.setRotation({ angle });
    }
    
    const pdfBytes = await pdf.save();
    const blob = new Blob([pdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    
    return {
        url,
        filename: 'rotated.pdf',
        blob,
    };
}

/**
 * Reorder PDF pages
 */
export async function reorderPdf(file, pageOrder) {
    const arrayBuffer = await file.arrayBuffer();
    const sourcePdf = await PDFDocument.load(arrayBuffer);
    const newPdf = await PDFDocument.create();
    
    // Copy pages in the specified order
    for (const pageNum of pageOrder) {
        const [copiedPage] = await newPdf.copyPages(sourcePdf, [pageNum - 1]);
        newPdf.addPage(copiedPage);
    }
    
    const pdfBytes = await newPdf.save();
    const blob = new Blob([pdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    
    return {
        url,
        filename: 'reordered.pdf',
        blob,
    };
}

/**
 * Parse page numbers string (e.g., "1,3,5-7") into array
 */
function parsePageNumbers(pageNumbersStr, maxPages) {
    const pages = new Set();
    const parts = pageNumbersStr.split(',');
    
    for (const part of parts) {
        const trimmed = part.trim();
        if (trimmed.includes('-')) {
            const [start, end] = trimmed.split('-').map(n => parseInt(n.trim()));
            for (let i = start; i <= end; i++) {
                if (i >= 1 && i <= maxPages) {
                    pages.add(i);
                }
            }
        } else {
            const num = parseInt(trimmed);
            if (num >= 1 && num <= maxPages) {
                pages.add(num);
            }
        }
    }
    
    return Array.from(pages).sort((a, b) => a - b);
}

// Make functions available globally for inline scripts
window.mergePdfs = mergePdfs;
window.splitPdf = splitPdf;
window.splitPdfIntoChunks = splitPdfIntoChunks;
window.compressPdf = compressPdf;
window.pdfToImage = pdfToImage;
window.imageToPdf = imageToPdf;
window.rotatePdf = rotatePdf;
window.reorderPdf = reorderPdf;
