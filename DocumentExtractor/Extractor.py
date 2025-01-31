import os
from neo4j import GraphDatabase

import fitz # PyMuPDF
from docx2pdf import convert

class Neo4jDatabase:
    def __init__ (self, neo4jURI: str, neo4jUSER: str, neo4jPASS: str):
        try:
            driver = GraphDatabase.driver(neo4jURI, auth=(neo4jUSER, neo4jPASS))
            driver.verify_connectivity()
            print("Connected to Neo4j successfully")
        except Exception as e:
            print(f"Failed to connect: {e}")
        finally:
            driver.close()


class Scrapers:
    def __init__ (self):
        scriptpath = os.path.dirname(os.path.abspath(__file__))
        outputfolder = f"{scriptpath}\\ScrapedFiles"

        if os.path.exists(outputfolder) and os.path.isdir(outputfolder):
            print("Output folder already exists...")
        else:
            os.mkdir("ScrapedFiles")
            print("Created new output folder...")
    def createdir(self, fileext, filename):
        outputpath = f"ScrapedFiles\\{filename}_{fileext}"
        if os.path.exists(outputpath) and os.path.isdir(outputpath):
            print("This document already has a folder...")
        else:
            os.mkdir(outputpath)
            print("Created new document folder...")
        return outputpath

    def pdf(self, filepath, filename, outputpath):
        doc = fitz.open(filepath)
        pagenum = 1
        for page in doc:
            out = open(f"{outputpath}\\{filename}_P{pagenum}.txt", "wb")
            text = page.get_text()
            # print(text.encode('utf8').decode('unicode_escape'), "\n")
            out.write(text.encode('utf8'))
            out.write(bytes((12,)))
            pagenum += 1
        out.close()


if __name__ == "__main__":
    NEO4J_URI = os.getenv("NEO4J_URI")
    NEO4J_USER = os.getenv("NEO4J_USER")
    NEO4J_PASSWORD = os.getenv("NEO4J_PASS")

    db = Neo4jDatabase(NEO4J_URI, NEO4J_USER, NEO4J_PASSWORD)
    scrape = Scrapers()

    path = "C:\\Repositories\\Backend-AISearchEngine\\Documents"
    supportedext = ["pdf","docx"]

    files = []
    for root, dirs, files in os.walk(path):
        for file in files:

            filename = file
            filepath = os.path.join(root, file)
            filedir = os.path.dirname(filepath)
            filename_noext = os.path.splitext(os.path.basename(file))[0]
            fileext = os.path.splitext(filepath)[1][1:]

            if fileext not in supportedext:
                print(f"Extension not currently supported: {fileext}")
            else:
                if fileext == "pdf":
                    outputpath = scrape.createdir(fileext, filename_noext)
                    scrape.pdf(filepath, filename, outputpath)
                elif fileext == "docx":
                    filename = f"{filename_noext}.pdf"
                    pdfpath = f"{filedir}\\{filename}"
                    convert(filepath, pdfpath)

                    outputpath = scrape.createdir("pdf", filename_noext)
                    scrape.pdf(pdfpath, filename, outputpath)

