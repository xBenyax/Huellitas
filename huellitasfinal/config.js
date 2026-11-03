export class Config {
  static API_BASE_URL = "/huellitas/api"; // PONGAN LA DIRECCION URL ACA

  // Carpeta donde XAMPP sirve los archivos físicos de fotos (hermana de /api).
  // Si tu proyecto vive en C:\xampp\htdocs\huellitas\, esto apunta a
  // C:\xampp\htdocs\huellitas\uploads\ a través de http://localhost/huellitas/uploads/
  static UPLOADS_BASE_URL = "/huellitas/uploads/";
}
