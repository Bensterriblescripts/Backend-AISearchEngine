using System.Data;
using System.Text;
using Neo4j.Driver;

namespace CodeExtractor;

public static class Queries
{
    private static (IAsyncSession?, String?) CreateConnection() {
        try {
            // Context
            String? uri = Environment.GetEnvironmentVariable("NEO4J_URI");
            String? user = Environment.GetEnvironmentVariable("NEO4J_USER");
            String? pass = Environment.GetEnvironmentVariable("NEO4J_PASS");
            if (uri == null || user == null || pass == null) {
                return (null, "Unable to locate a Neo4j environment variable, please check they are configured. [NEO4J_URI, NEO4J_USER, NEO4J_PASS]");
            }

            IDriver driver = GraphDatabase.Driver(uri, AuthTokens.Basic(user, pass));
            IAsyncSession session = driver.AsyncSession();

            return (session, null);
        }
        catch (Exception e) {
            return (null, $"Error: {e.Message}");
        }
    }
    public static async Task<String?> RunQuery(List<String> namespaces, Dictionary<StringBuilder, Extractor.ClassInfo> classes, Dictionary<StringBuilder, Extractor.FunctionInfo> functions, StringBuilder open) {
        
        try {
            (IAsyncSession? conn, String? error) = CreateConnection();
            if (conn == null) {
                return error;
            }
            
            Console.WriteLine("Connected to Neo4j...");
            IResultCursor result = await conn.RunAsync("MATCH (n) RETURN n");
            Console.WriteLine("Retrieved query result from Neo4j...");

            if (result == null) {
                return "Query failed or returned nothing.";
            }
            return result.ToString();

        }
        catch (Exception e) {
            return $"Exception while connecting to the Neo4j database: {e.Message}";
        }

        // if (classes.Count > 0 && functions.Count > 0 && open.Length > 0) {
        //     for 
        // }
    }

    private static void BuildFunctionQuery() {
        return;
    }
    private static void BuildClassQuery() {
        return;
    }
    private static void BuildFunctionOnlyQuery() {
        
    }
    private static void BuildClassOnlyQuery() {
        
    }
}