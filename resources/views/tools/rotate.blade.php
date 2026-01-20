@extends('layouts.app')

@section('title', $metadata['title'])
@section('description', $metadata['description'])
@section('keywords', $metadata['keywords'])

@section('schema')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "{{ $metadata['name'] }}",
    "description": "{{ $metadata['description'] }}",
    "url": "{{ url()->current() }}",
    "applicationCategory": "UtilityApplication",
    "operatingSystem": "Any",
    "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "USD"
    }
}
</script>
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-white rounded-lg shadow-sm p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $metadata['name'] }}</h1>
        <p class="text-gray-600 mb-8">{{ $metadata['description'] }}</p>

        @include('components.operation-counter')

        <div x-data="pdfRotator()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select PDF file</label>
                <input type="file" @change="handleFile($event.target.files)" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- Rotation Options -->
            <div x-show="totalPages > 0" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pages to rotate</label>
                    <select x-model="pageSelection" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all">All pages</option>
                        <option value="range">Page range</option>
                    </select>
                    
                    <div x-show="pageSelection === 'range'" class="mt-2">
                        <input type="number" x-model="startPage" min="1" :max="totalPages" placeholder="Start page" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <input type="number" x-model="endPage" min="1" :max="totalPages" placeholder="End page" class="block w-full mt-2 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    
                    <p class="mt-1 text-sm text-gray-500">Total pages: <span x-text="totalPages"></span></p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Rotation angle</label>
                    <div class="flex space-x-4">
                        <button @click="rotationAngle = 90" 
                                :class="rotationAngle === 90 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'"
                                class="px-4 py-2 rounded-lg font-semibold">90°</button>
                        <button @click="rotationAngle = 180" 
                                :class="rotationAngle === 180 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'"
                                class="px-4 py-2 rounded-lg font-semibold">180°</button>
                        <button @click="rotationAngle = 270" 
                                :class="rotationAngle === 270 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'"
                                class="px-4 py-2 rounded-lg font-semibold">270°</button>
                    </div>
                </div>

                <button @click="rotatePdf()" 
                        :disabled="processing"
                        class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                        x-text="processing ? 'Rotating...' : 'Rotate PDF'">
                </button>
            </div>

            <!-- Download Link -->
            <div x-show="downloadUrl" class="text-center">
                <a :href="downloadUrl" :download="downloadFilename" 
                   class="inline-block bg-green-600 text-white py-3 px-6 rounded-lg font-semibold hover:bg-green-700">
                    Download Rotated PDF
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function pdfRotator() {
    return {
        file: null,
        totalPages: 0,
        pageSelection: 'all',
        startPage: 1,
        endPage: 1,
        rotationAngle: 90,
        processing: false,
        downloadUrl: null,
        downloadFilename: 'rotated.pdf',

        async handleFile(fileList) {
            if (fileList.length === 0) return;
            this.file = fileList[0];
            try {
                const pdfDoc = await PDFLib.PDFDocument.load(await this.file.arrayBuffer());
                this.totalPages = pdfDoc.getPageCount();
                this.endPage = this.totalPages;
            } catch (error) {
                alert('Error loading PDF: ' + error.message);
            }
        },

        async rotatePdf() {
            if (!this.file) return;
            
            this.processing = true;
            try {
                let pages = [];
                if (this.pageSelection === 'all') {
                    pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);
                } else {
                    pages = Array.from({ length: this.endPage - this.startPage + 1 }, (_, i) => this.startPage + i);
                }
                
                const result = await rotatePdf(this.file, pages, this.rotationAngle);
                this.downloadUrl = result.url;
                this.downloadFilename = result.filename;
                
                await this.trackOperation('rotate');
            } catch (error) {
                alert('Error rotating PDF: ' + error.message);
            } finally {
                this.processing = false;
            }
        },

        async trackOperation(type) {
            try {
                const response = await fetch('/operations/track', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ operation_type: type })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    window.dispatchEvent(new CustomEvent('operations-updated', { detail: data.operations_remaining }));
                }
            } catch (error) {
                console.error('Failed to track operation:', error);
            }
        }
    }
}
</script>
@endpush
@endsection
